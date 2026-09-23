<?php

namespace Sendelius\Queue;

class Container {
	private static ?Process $process = null;
	private static ?Register $register = null;
	private static ?Push $push = null;
	/**
	 * @var Storage[]
	 */
	private static array $storages = [];
	private static array $handlers = [];

	public static function process(): Process {
		if (!self::$process) self::$process = new Process();
		return self::$process;
	}

	public static function register(): Register {
		if (!self::$register) self::$register = new Register();
		return self::$register;
	}

	public static function push(): Push {
		if (!self::$push) self::$push = new Push();
		return self::$push;
	}

	public static function storage(): Storage {
		if (!isset(self::$storages['current'])) {
			$storage = new Storage();
			self::$storages['current'] = $storage;
			self::$storages[$storage->id()] = $storage;
		}
		return self::$storages['current'];
	}

	public static function storageById(string $id): Storage {
		if (!isset(self::$storages[$id])) self::$storages[$id] = new Storage($id);
		return self::$storages[$id];
	}

	public static function getHandler(string $queue): ?array {
		return self::$handlers[$queue] ?? null;
	}

	public static function setHandler(string $queue, callable $handler, int $interval = 0): void {
		self::$handlers[$queue] = ['handler' => $handler, 'interval' => $interval];
	}
}