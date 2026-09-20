<?php

namespace Sendelius\Queue;

use JsonException;
use RuntimeException;

readonly class QueueStorage {
	public function __construct(
		private string $directory,
		private string $id,
	) {
		if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
			throw new RuntimeException("не удалось создать хранилище очереди");
		}
	}

	public function id(): string {
		return $this->id;
	}

	public function put(string $key, mixed $data): void {
		$file = $this->file($key);
		$temp = $file . '.tmp';
		try {
			$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("ошибка обработки json: " . $e->getMessage(), 0, $e);
		}
		if ($json === false || file_put_contents($temp, $json, LOCK_EX) === false) {
			throw new RuntimeException("не удалось записать данные очереди");
		}
		rename($temp, $file);
	}

	public function get(string $key): mixed {
		$file = $this->file($key);
		if (!is_file($file)) {
			return null;
		}
		$data = json_decode(file_get_contents($file), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException("ошибка чтения данных очереди");
		}
		return $data;
	}

	public function has(string $key): bool {
		return is_file($this->file($key));
	}

	public function list(): array {
		$files = glob($this->directory . '*.json') ?: [];
		return array_map(fn(string $file) => pathinfo($file, PATHINFO_FILENAME), $files);
	}

	public function delete(string $key): void {
		$file = $this->file($key);
		if (is_file($file)) {
			unlink($file);
		}
	}

	public function clear(): void {
		foreach ($this->list() as $key) {
			$this->delete($key);
		}
	}

	private function file(string $key): string {
		$key = preg_replace('/[^a-zA-Z0-9._-]/', '_', $key);
		return $this->directory . $key . '.json';
	}
}