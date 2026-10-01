<?php

namespace Sendelius\Queue;

use JsonException;
use RuntimeException;
use Sendelius\Config\Env;

final class Push extends Resources {
	// Добавить задачу
	public function push(string $queue, ?array $payload = null, ?string $availableAt = null, ?string $storageId = null): int {
		if (!Env::bool('QUEUE_ALLOW')) {
			return 0;
		}
		try {
			$payload = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
		} catch (JsonException $e) {
			throw new RuntimeException("ошибка обработки json: " . $e->getMessage(), 0, $e);
		}
		if (empty($availableAt)) {
			$handler = Container::getHandler($queue);
			$interval = ($handler and $handler['interval']) ? $handler['interval'] : 0;
			$availableAt = date('Y-m-d H:i:s', time() + $interval);
		}
		return $this->table()->insert([
			'queue' => $queue,
			'payload' => $payload,
			'storage_id' => $storageId,
			'available_at' => $availableAt ?? date('Y-m-d H:i:s'),
		]);
	}
}