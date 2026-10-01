<?php

namespace Sendelius\Db;

use RuntimeException;
use Sendelius\Infrastructure\Schema;

final class RegistrySchema {
	/**
	 * @var Schema[]
	 */
	private static array $schemas = [];

	public static function add(string $schema): void {
		if (!is_a($schema, Schema::class, true)) {
			throw new RuntimeException("класс {$schema} должен наследоваться от " . Schema::class);
		}
		$schema = new $schema();
		self::$schemas[$schema->table()] = $schema;
	}

	/**
	 * @return Schema[]
	 */
	public static function all(): array {
		return self::$schemas;
	}

	public static function get(string $table): ?Schema {
		return self::$schemas[$table] ?? null;
	}
}