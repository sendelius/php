<?php

namespace Sendelius\Infrastructure;

use Sendelius\Queue\Container;
use Sendelius\Queue\Register;

class Queue {
	public function register(): Register {
		return Container::register();
	}

	public function push(string $queue, ?array $payload = null, ?string $availableAt = null, ?string $storageId = null): int {
		return (Container::push())->push(
			queue: $queue,
			payload: $payload,
			availableAt: $availableAt,
			storageId: $storageId,
		);
	}
}