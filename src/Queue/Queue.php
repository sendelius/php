<?php

namespace Sendelius\Queue;

use JsonException;
use Ramsey\Uuid\Uuid;
use Random\RandomException;
use RuntimeException;
use Sendelius\Config\Env;
use Sendelius\Db\Database;
use Sendelius\Db\MySQL;
use Sendelius\Db\PostgreSQL;
use Sendelius\Redis\Redis;
use Throwable;

final class Queue {
	private array $handlers = [];
	private ?string $lockToken = null;
	private QueueStorageFactory $storageFactory;

	public function __construct() {
		$this->storageFactory = new QueueStorageFactory(APP_DIR . 'storage' . DS . 'queue' . DS);
	}

	private function table(): MySQL|PostgreSQL {
		return match (Env::string('QUEUE_DB_TYPE', 'mysql')) {
			'postgresql' => (new PostgreSQL())->table(Env::string('QUEUE_DB_TABLE', 'queue')),
			default => (new MySQL())->table(Env::string('QUEUE_DB_TABLE', 'queue')),
		};
	}

	private function redis(): Redis {
		return new Redis(
			prefix: 'queue',
		);
	}

	// Зарегистрировать обработчик
	public function handler(string $queue, callable $handler, int $interval = 0): self {
		$this->handlers[$queue] = ['handler' => $handler, 'interval' => $interval];
		return $this;
	}

	// Зарегистрировать постоянную задачу
	public function schedule(string $name, string $schedule, callable $handler): self {
		$this->handler($name, $handler);
		$job = $this->table()->where(['name' => $name, 'permanent' => 1])->get();
		if (!$job) {
			$this->table()->insert([
				'id' => Uuid::uuid4()->toString(),
				'name' => $name,
				'queue' => $name,
				'permanent' => 1,
				'schedule' => $schedule,
				'available_at' => $this->nextSchedule($schedule),
			]);
			return $this;
		}
		if ($job['schedule'] !== $schedule) {
			$this->table()->where(['id' => $job['id']])->update([
				'schedule' => $schedule,
				'available_at' => $this->nextSchedule($schedule),
			]);
		}
		return $this;
	}

	// Добавить задачу
	public function push(string $queue, ?array $payload = null, ?QueueStorage $storage = null, ?string $availableAt = null): int {
		try {
			$payload = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
		} catch (JsonException $e) {
			throw new RuntimeException("ошибка обработки json: " . $e->getMessage(), 0, $e);
		}
		return $this->table()->insert([
			'id' => Uuid::uuid4()->toString(),
			'queue' => $queue,
			'payload' => $payload,
			'storage_id' => $storage?->id(),
			'available_at' => $availableAt ?? date('Y-m-d H:i:s'),
		]);
	}

	// Добавить следующую задачу с учётом interval
	public function pushNext(string $queue, ?array $payload = null, ?QueueStorage $storage = null): int {
		$interval = $this->handlers[$queue]['interval'] ?? 0;
		return $this->push(
			$queue,
			$payload,
			$storage,
			date('Y-m-d H:i:s', time() + $interval),
		);
	}

	// Получить storage
	public function storage(): QueueStorage {
		return $this->storageFactory->create();
	}

	// Запустить worker
	public function process(int $limit = 100): int {
		if (!$this->lock()) {
			return 0;
		}
		$processed = 0;
		try {
			while ($processed < $limit) {
				$job = $this->next();
				if (!$job) {
					break;
				}
				$this->run($job);
				$processed++;
			}
			return $processed;
		} finally {
			$this->unlock();
		}
	}

	// Получить следующую задачу
	private function next(): ?array {
		return $this->table()->transaction(function (Database $database): ?array {
			$table = Env::string('QUEUE_DB_TABLE', 'queue');
			$job = $database->custom("SELECT *
            FROM {$table}
            WHERE status = 'pending'
              AND available_at <= NOW()
            ORDER BY id
            LIMIT 1
            FOR UPDATE", [], 'one');

			if (!$job) {
				return null;
			}

			$startedAt = date('Y-m-d H:i:s');
			$attempts = ((int)$job['attempts']) + 1;

			$database->where(['id' => $job['id']])->update([
				'status' => 'processing',
				'started_at' => $startedAt,
				'attempts' => $attempts,
			]);

			$job['status'] = 'processing';
			$job['started_at'] = $startedAt;
			$job['attempts'] = $attempts;

			return $job;
		});
	}

	// Выполнить задачу
	private function run(array $data): void {
		$config = $this->handlers[$data['queue']] ?? null;
		if (!$config) {
			throw new RuntimeException("обработчик очереди не найден: {$data['queue']}");
		}
		$job = new QueueJob(
			data: $data,
			queue: $this,
			storageFactory: $this->storageFactory,
		);
		try {
			($config['handler'])($job);
			$this->done((string)$data['id']);
		} catch (Throwable $e) {
			if ((int)$data['attempts'] >= Env::int('QUEUE_MAX_ATTEMPTS', 3)) {
				$this->failed((string)$data['id'], $e->getMessage());
			} else {
				$this->retry((string)$data['id'], 60, $e->getMessage());
			}
			throw new RuntimeException("ошибка очереди: " . $e->getMessage(), 0, $e);
		}
	}

	// Завершить задачу
	public function done(string $id): bool {
		$job = $this->table()->where(['id' => $id])->get();
		if (!$job) {
			return false;
		}

		if ((int)$job['permanent'] === 1) {
			return $this->table()->where(['id' => $id])->update([
				'status' => 'pending',
				'available_at' => $this->nextSchedule($job['schedule']),
				'finished_at' => date('Y-m-d H:i:s'),
				'error' => null,
			]);
		}

		return $this->table()->where(['id' => $id])->update([
			'status' => 'done',
			'finished_at' => date('Y-m-d H:i:s'),
			'error' => null,
		]);
	}

	// Вернуть задачу в очередь
	public function retry(string $id, int $delay = 60, ?string $error = null): bool {
		return $this->table()->where(['id' => $id])->update([
			'status' => 'pending',
			'available_at' => date('Y-m-d H:i:s', time() + $delay),
			'error' => $error,
		]);
	}

	// Окончательно завершить с ошибкой
	public function failed(string $id, ?string $error = null): bool {
		return $this->table()->where(['id' => $id])->update([
			'status' => 'failed',
			'finished_at' => date('Y-m-d H:i:s'),
			'error' => $error,
		]);
	}

	// Количество ожидающих задач
	public function count(?string $queue = null): int {
		$items = $this->table()->where(['status' => 'pending']);
		if ($queue !== null) {
			$items->where(['queue' => $queue]);
		}
		return $items->count();
	}

	// Заблокировать worker
	private function lock(): bool {
		try {
			$this->lockToken = bin2hex(random_bytes(16));
		} catch (RandomException $e) {
			throw new RuntimeException("ошибка random_bytes: " . $e->getMessage(), 0, $e);
		}
		$result = $this->redis()->set(
			Env::string('QUEUE_LOCK_KEY', 'process'),
			$this->lockToken,
			Env::int('QUEUE_LOCK_TTL', 3600),
			true,
		);
		if (!$result) {
			$this->lockToken = null;
			return false;
		}
		return true;
	}

	// Снять блокировку
	private function unlock(): void {
		if ($this->lockToken === null) {
			return;
		}
		$script = "
			if redis.call('GET', KEYS[1]) == ARGV[1] then
				return redis.call('DEL', KEYS[1])
			end
			return 0
		";
		(bool)$this->redis()->eval(
			$script,
			[Env::string('QUEUE_LOCK_KEY', 'process')],
			[$this->lockToken],
		);
		$this->lockToken = null;
	}

	private function nextSchedule(string $schedule): string {
		$parts = preg_split('/\s+/', trim($schedule));
		if (count($parts) !== 5) {
			throw new RuntimeException("некорректное расписание: {$schedule}");
		}
		[$minute, $hour, $day, $month, $weekday] = $parts;
		$time = time() + 60;
		for ($i = 0; $i < 366 * 24 * 60; $i++, $time += 60) {
			$date = getdate($time);
			if (
				!$this->cronMatch($date['minutes'], $minute, 0, 59) ||
				!$this->cronMatch($date['hours'], $hour, 0, 23) ||
				!$this->cronMatch($date['mon'], $month, 1, 12)
			) {
				continue;
			}
			$dayMatch = $this->cronMatch($date['mday'], $day, 1, 31);
			$weekdayMatch = $this->cronMatch($date['wday'], $weekday, 0, 6);
			if ($day === '*' && $weekday === '*') {
				$dateMatch = true;
			} elseif ($day === '*') {
				$dateMatch = $weekdayMatch;
			} elseif ($weekday === '*') {
				$dateMatch = $dayMatch;
			} else {
				$dateMatch = $dayMatch || $weekdayMatch;
			}
			if (!$dateMatch) {
				continue;
			}
			return date('Y-m-d H:i:s', $time);
		}
		throw new RuntimeException("не удалось вычислить следующее выполнение: {$schedule}");
	}

	private function cronMatch(int $value, string $expression, int $min, int $max): bool {
		foreach (explode(',', $expression) as $part) {
			if (str_contains($part, '/')) {
				[$range, $step] = explode('/', $part, 2);
				$step = (int)$step;
				if ($step < 1) {
					return false;
				}
				if ($range === '*') {
					$start = $min;
					$end = $max;
				} elseif (str_contains($range, '-')) {
					[$start, $end] = array_map('intval', explode('-', $range, 2));
				} else {
					$start = (int)$range;
					$end = $max;
				}
				if ($value >= $start && $value <= $end && ($value - $start) % $step === 0) {
					return true;
				}
				continue;
			}
			if ($part === '*') {
				return true;
			}
			if (str_contains($part, '-')) {
				[$start, $end] = array_map('intval', explode('-', $part, 2));
				if ($value >= $start && $value <= $end) {
					return true;
				}
				continue;
			}
			if ((int)$part === $value) {
				return true;
			}
		}
		return false;
	}
}