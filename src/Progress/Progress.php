<?php

namespace Sendelius\Progress;

use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Sendelius\Config\Env;
use Sendelius\Redis\Redis;

class Progress {
	private static ?string $id = null;

	public function __construct(
		?string $id = null,
	) {
		if (!empty($id)) self::$id = $id;
		elseif (!self::$id) self::$id = Uuid::uuid4()->toString();
	}

	private function redis(): Redis {
		return new Redis(
			prefix: 'progress',
		);
	}

	public function start(?int $total = null, ?string $message = null): bool {
		return $this->set([
			'status' => 'processing',
			'progress' => 0,
			'total' => $total,
			'message' => $message,
		]);
	}

	public function update(int $progress, ?int $total = null, ?string $message = null): bool {
		$data = $this->get() ?? [
			'status' => 'processing',
			'progress' => 0,
			'total' => null,
			'message' => null,
		];
		$data['status'] = 'processing';
		$data['progress'] = $progress;
		if ($total !== null) {
			$data['total'] = $total;
		}
		if ($message !== null) {
			$data['message'] = $message;
		}
		return $this->set($data);
	}

	public function done(?string $message = null): bool {
		$data = $this->get() ?? [];
		$data['status'] = 'done';
		$data['progress'] = $data['total'] ?? $data['progress'] ?? null;
		if ($message !== null) {
			$data['message'] = $message;
		}
		return $this->set($data);
	}

	public function failed(string $message): bool {
		$data = $this->get() ?? [];
		$data['status'] = 'failed';
		$data['message'] = $message;
		return $this->set($data);
	}

	public function get(): ?array {
		if (!self::$id) return null;
		$value = $this->redis()->get(self::$id);
		if ($value === false || $value === null) {
			return null;
		}
		try {
			return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("ошибка обработки json: " . $e->getMessage(), 0, $e);
		}
	}

	public function exists(): bool {
		return self::$id && $this->redis()->exists(self::$id);
	}

	public function delete(): bool {
		return self::$id && $this->redis()->delete(self::$id);
	}

	private function set(array $data): bool {
		if (!self::$id) return false;
		$data['updated_at'] = time();
		return $this->redis()->set(self::$id, $data, Env::int('PROGRESS_TTL', 86400));
	}
}