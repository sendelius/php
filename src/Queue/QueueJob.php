<?php

namespace Sendelius\Queue;

readonly class QueueJob {
	public function __construct(
		private array               $data,
		private Queue               $queue,
		private QueueStorageFactory $storageFactory,
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

	public function storage(): ?QueueStorage {
		if (empty($this->data['storage_id'])) {
			return null;
		}
		return $this->storageFactory->get($this->data['storage_id']);
	}

	public function push(string $queue, ?array $payload = null, ?QueueStorage $storage = null): int {
		return $this->queue->push($queue, $payload, $storage ?? $this->storage());
	}

	public function pushNext(string $queue, ?array $payload = null, ?QueueStorage $storage = null): int {
		return $this->queue->pushNext($queue, $payload, $storage ?? $this->storage());
	}

	public function data(): array {
		return $this->data;
	}
}