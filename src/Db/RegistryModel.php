<?php

namespace Sendelius\Db;

use RuntimeException;
use Sendelius\Infrastructure\Model;

final class RegistryModel {
	/**
	 * @var Model[]
	 */
	private static array $models = [];

	public static function add(string $model): void {
		if (!is_a($model, Model::class, true)) {
			throw new RuntimeException("класс {$model} должен наследоваться от " . Model::class);
		}
		$model = new $model();
		self::$models[$model->table()] = $model;
	}

	/**
	 * @return Model[]
	 */
	public static function all(): array {
		return self::$models;
	}

	public static function get(string $table): ?Model {
		return self::$models[$table] ?? null;
	}
}