<?php

namespace Sendelius\Http;

class Request {
	private array $query;

	public function __construct(
		private readonly array $params
	) {
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
		$this->query = match (true) {
			str_contains($contentType, 'application/json') => $this->parseJsonBody(),
			str_contains($contentType, 'application/x-www-form-urlencoded') => $_POST ?? [],
			$_SERVER['REQUEST_METHOD'] === 'GET' => $_GET ?? [],
			default => []
		};
	}

	public function param(string $name, $default = null): mixed {
		return $this->params[$name] ?? $default;
	}

	public function query(string $name, $default = null): mixed {
		return $this->query[$name] ?? $default;
	}

	private function parseJsonBody(): array {
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
		if (!str_contains($contentType, 'application/json')) return [];
		$raw = file_get_contents('php://input');
		if (!$raw) return [];
		$data = (json_validate($raw)) ? json_decode($raw, true) : [];
		return is_array($data) ? $data : [];
	}
}