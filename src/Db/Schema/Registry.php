<?php

namespace Sendelius\Db\Schema;

use RuntimeException;
use Sendelius\Infrastructure\Schema;

final class Registry {
	private static array $schemas = [];

	public static function add(string $schema): void {
		if (!is_a($schema, Schema::class, true)) {
			throw new RuntimeException("класс {$schema} должен наследоваться от " . Schema::class);
		}
		self::$schemas[$schema] = true;
	}

	public static function remove(string $schema): void {
		unset(self::$schemas[$schema]);
	}

	/**
	 * @return class-string<Schema>[]
	 */
	public static function all(): array {
		return array_keys(self::$schemas);
	}

	public static function get(string $table): ?Schema {
		foreach (self::$schemas as $schema => $value) {
			$schema = new $schema();
			if ($schema->name() === $table) {
				return $schema;
			}
		}
		return null;
	}

	public static function clear(): void {
		self::$schemas = [];
	}
}