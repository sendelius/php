<?php

namespace Sendelius\Session;

use Sendelius\Infrastructure\Schema;

class SessionSchema extends Schema {
	public function name(): string {
		return 'sessions';
	}

	public function schema(): array {
		return [
			'id' => $this->char()->length(36)->primary(),
			'token' => $this->char()->length(64)->unique()->index(),
			'user_id' => $this->int()->index(),
			'expired' => $this->int()->index(),
			'request' => $this->json(),
			'created_at' => $this->date(),
			'updated_at' => $this->date()->onUpdate('NOW()'),
		];
	}
}