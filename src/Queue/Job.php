<?php

namespace Sendelius\Queue;

final class Job {
	public function __construct(
		private array $data,
	) {
	}

	public function id(): string {
		return (string)$this->data['id'];
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
			$storage = Container::storage();
			$this->data['storage_id'] = $storage->id();
		}
		return Container::storageById($this->data['storage_id']);
	}

	public function next(?string $queue = null, ?array $payload = null): int {
		return Container::push()->push(
			queue: $queue ?? $this->queue(),
			payload: $payload,
			availableAt: date('Y-m-d H:i:s'),
			storageId: $this->data['storage_id'],
		);
	}

	public function data(): array {
		return $this->data;
	}
}