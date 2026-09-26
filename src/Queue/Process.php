<?php

namespace Sendelius\Queue;

use Random\RandomException;
use RuntimeException;
use Sendelius\Config\Env;
use Sendelius\Db\Database;
use Throwable;

final class Process extends Resources {
	private ?string $lockToken = null;

	// Запустить worker
	public function process(): int {
		if (!$this->lock()) {
			return 0;
		}
		$processed = 0;
		$maxTime = Env::int('QUEUE_MAX_TIME', 3600);
		$jobMaxTime = Env::int('QUEUE_JOB_MAX_TIME', 1800);
		$deadline = time() + $maxTime;
		try {
			$this->cleanup();
			ini_set('max_execution_time', (string)($maxTime + $jobMaxTime));
			while ($processed < Env::int('QUEUE_LIMIT', 100) && time() < $deadline) {
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

	// Количество ожидающих задач
	public function count(?string $queue = null): int {
		$items = $this->table()->where(['status' => 'pending']);
		if ($queue !== null) {
			$items->where(['queue' => $queue]);
		}
		return $items->count();
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
		$handler = Container::getHandler($data['queue']);
		if (!$handler) {
			throw new RuntimeException("обработчик очереди не найден: {$data['queue']}");
		}
		$job = new Job($data);
		try {
			($handler['handler'])($job);
			$this->done((string)$data['id']);
		} catch (Throwable $e) {
			if ((int)$data['attempts'] >= Env::int('QUEUE_MAX_ATTEMPTS', 3)) {
				$this->failed((string)$data['id'], $e->getMessage());
			} else {
				$this->retry((string)$data['id'], $e->getMessage());
			}
			throw new RuntimeException("ошибка очереди: " . $e->getMessage(), 0, $e);
		}
	}

	// Завершить задачу
	private function done(string $id): void {
		$job = $this->table()->where(['id' => $id])->get();
		if (!$job) {
			return;
		}
		if ((int)$job['permanent'] === 1) {
			$this->table()->where(['id' => $id])->update([
				'status' => 'pending',
				'available_at' => $this->nextSchedule($job['schedule']),
				'finished_at' => date('Y-m-d H:i:s'),
				'error' => null,
				'attempts' => 0,
			]);
			return;
		}
		$this->table()->where(['id' => $id])->delete();
	}

	// Вернуть задачу в очередь
	private function retry(string $id, ?string $error = null): void {
		$this->table()->where(['id' => $id])->update([
			'status' => 'pending',
			'available_at' => date('Y-m-d H:i:s', time() + 60),
			'error' => $error,
		]);
	}

	// Окончательно завершить с ошибкой
	private function failed(string $id, ?string $error = null): void {
		$job = $this->table()->where(['id' => $id])->get();
		if (!$job) {
			return;
		}
		if ((int)$job['permanent'] === 1) {
			$this->table()->where(['id' => $id])->update([
				'status' => 'pending',
				'available_at' => $this->nextSchedule($job['schedule']),
				'finished_at' => date('Y-m-d H:i:s'),
				'error' => $error,
				'attempts' => 0,
			]);
			return;
		}
		$this->table()->where(['id' => $id])->update([
			'status' => 'failed',
			'finished_at' => date('Y-m-d H:i:s'),
			'error' => $error,
		]);
	}

	private function cleanup(): void {
		$this->table()->where([
			'status' => 'failed',
			'finished_at <' => date('Y-m-d H:i:s', time() - 604800),
		])->delete();
	}

	// Заблокировать worker
	private function lock(): bool {
		try {
			$this->lockToken = bin2hex(random_bytes(16));
		} catch (RandomException $e) {
			throw new RuntimeException("ошибка random_bytes: " . $e->getMessage(), 0, $e);
		}
		$result = $this->redis()->set(
			'process',
			$this->lockToken,
			Env::int('QUEUE_MAX_TIME', 3600),
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
			['process'],
			[$this->lockToken],
		);
		$this->lockToken = null;
	}
}