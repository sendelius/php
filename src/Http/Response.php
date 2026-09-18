<?php

namespace Sendelius\Http;

class Response {
	private array $data = [
		'result' => [],
		'status' => 200,
		'error' => null,
	];

	public function result(array $value): void {
		$this->data['result'] = $value;
	}

	public function status(int $value): void {
		$this->data['status'] = $value;
	}

	public function error(string $value, ?string $field = null): void {
		$this->data['error'] = $value;
		if ($field) $this->data['error_field'] = $field;
		exit();
	}

	public function getStatus(): int {
		return $this->data['status'];
	}

	public function getError(): ?string {
		return $this->data['error'];
	}

	public function getErrorField(): ?string {
		return $this->data['error_field'] ?? null;
	}

	public function getResult(): array {
		return $this->data['result'];
	}
}