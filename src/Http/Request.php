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

	public function bearerToken(): ?string {
		$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
		if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
			return $matches[1];
		}
		return null;
	}

	public function domain(): string {
		return $_SERVER['HTTP_HOST'] ?? '';
	}

	public function mainDomain(): string {
		$parts = explode('.', $this->domain());
		return implode('.', array_slice($parts, -2));
	}

	public function userIp(): string {
		return $_SERVER['REMOTE_ADDR'] ?? '';
	}

	public function userAgent(): string {
		return $_SERVER['HTTP_USER_AGENT'] ?? '';
	}

	public function browser(): ?string {
		$userAgent = $this->userAgent();
		if (stripos($userAgent, 'Windows') !== false) return 'Windows';
		elseif (stripos($userAgent, 'Mac') !== false) return 'Mac';
		elseif (stripos($userAgent, 'Linux') !== false) return 'Linux';
		return null;
	}

	public function os(): ?string {
		$userAgent = $this->userAgent();
		if (preg_match('/Firefox\/([0-9.]+)/', $userAgent, $matches)) {
			return 'Firefox ' . $matches[1];
		} elseif (preg_match('/Chrome\/([0-9.]+)/', $userAgent, $matches)) {
			return 'Chrome ' . $matches[1];
		} elseif (preg_match('/Version\/([0-9.]+).*Safari/', $userAgent, $matches) && stripos($userAgent, 'Chrome') === false) {
			return 'Safari ' . $matches[1];
		}
		return null;
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