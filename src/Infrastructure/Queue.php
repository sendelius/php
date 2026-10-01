<?php

namespace Sendelius\Infrastructure;

use Sendelius\Config\Env;
use Sendelius\Queue\Container;
use Sendelius\Queue\Register;

abstract class Queue {
	protected function register(): Register {
		return Container::register();
	}

	protected function push(string $queue, ?array $payload = null, ?string $availableAt = null, ?string $storageId = null): int {
		if (!Env::bool('QUEUE_ALLOW')) {
			return 0;
		}
		return (Container::push())->push(
			queue: $queue,
			payload: $payload,
			availableAt: $availableAt,
			storageId: $storageId,
		);
	}
}