<?php

namespace Sendelius\Queue;

readonly class Job {
	public function __construct(
		private array $data,
	) {
	}

	public function id(): int {
		return (int)$this->data['id'];
	}

	public function queue(): string {
		return $this->data['queue'];
	}

	public function payload(): ?array {
		if (empty($this->data['payload'])) {
			return null;
		}
		return json_decode($this->data['payload'], true);
	}

	public function storage(): ?Storage {
		if (empty($this->data['storage_id'])) {
			return null;
		}
		return Container::storageById($this->data['storage_id']);
	}

	public function next(?string $queue = null, ?array $payload = null): int {
		$push = Container::push();
		return $push->push(
			queue: $queue ?? $this->queue(),
			payload: $payload,
			storageId: $this->storage()->id(),
		);
	}

	public function data(): array {
		return $this->data;
	}
}