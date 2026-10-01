<?php

namespace Sendelius\Db;

use RuntimeException;
use Sendelius\Infrastructure\Schema;

final class Registry {
	private static array $schemas = [];

	public static function add(string $schema): void {
		if (!is_a($schema, Schema::class, true)) {
			throw new RuntimeException("класс {$schema} должен наследоваться от " . Schema::class);
		}
		self::$schemas[$schema] = $schema;
	}

	public static function remove(string $schema): void {
		if (isset(self::$schemas[$schema])) unset(self::$schemas[$schema]);
	}

	/**
	 * @return class-string<Schema>[]
	 */
	public static function all(): array {
		return array_keys(self::$schemas);
	}

	public static function get(string $table): ?Schema {
		foreach (self::$schemas as $schema) {
			$schema = new $schema();
			if ($schema->table() === $table) {
				return $schema;
			}
		}
		return null;
	}

	public static function clear(): void {
		self::$schemas = [];
	}
}