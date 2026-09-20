<?php

namespace Sendelius\Queue;

use Random\RandomException;
use RuntimeException;

class QueueStorageFactory {
	private string $directory;

	public function __construct(string $directory) {
		$this->directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	}

	public function create(): QueueStorage {
		try {
			$id = bin2hex(random_bytes(16));
		} catch (RandomException $e) {
			throw new RuntimeException("ошибка random_bytes: " . $e->getMessage(), 0, $e);
		}
		return $this->get($id);
	}

	public function get(string $id): QueueStorage {
		return new QueueStorage($this->directory . $id . DIRECTORY_SEPARATOR, $id);
	}
}