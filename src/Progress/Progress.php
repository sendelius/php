<?php

namespace Sendelius\Progress;

use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Sendelius\Redis\Redis;

class Progress {
	private const int TTL = 86400;
	private string $id;

	public function __construct(
		?string                 $id = null,
		private readonly string $redisPrefix = 'progress',
	) {
		$this->id = (!$id) ? Uuid::uuid4()->toString() : $id;
	}

	private function redis(): Redis {
		return new Redis(
			prefix: $this->redisPrefix,
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
		$value = $this->redis()->get($this->id);
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
		return $this->redis()->exists($this->id);
	}

	public function delete(): bool {
		return $this->redis()->delete($this->id);
	}

	private function set(array $data): bool {
		$data['updated_at'] = time();
		return $this->redis()->set($this->id, $data, self::TTL);
	}
}