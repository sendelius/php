<?php

namespace Sendelius\Queue;

use Sendelius\Infrastructure\Schema;

class QueueSchema extends Schema {
	public function schema(): array {
		return [
			'id' => $this->char()->length(36)->primary(),
			'name' => $this->char()->var()->length(100)->index(),
			'queue' => $this->char()->var()->length(150)->index(),
			'payload' => $this->json(),
			'storage_id' => $this->char()->var()->length(100)->index(),
			'permanent' => $this->boolean()->index(),
			'schedule' => $this->char()->var()->length(100),
			'status' => $this->enum()->options([
				'pending', 'processing', 'done', 'failed'
			])->default('pending')->index(),
			'attempts' => $this->int(),
			'error' => $this->char()->text(),
			'available_at' => $this->date()->index(),
			'started_at' => $this->date()->default(null),
			'finished_at' => $this->date()->default(null),
			'created_at' => $this->date(),
			'updated_at' => $this->date()->onUpdate('NOW()'),
		];
	}
}