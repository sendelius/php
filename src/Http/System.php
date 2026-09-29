<?php

namespace Sendelius\Http;

use Sendelius\Config\Env;

final class System {
	public static function register(Router $router): void {
		if (!Env::bool('DEV_MODE')) {
			return;
		}

		$router->route('GET /system/schema', self::class, 'schema');
		$router->route('GET /system/schema/{table}', self::class, 'table');
		$router->route('POST /system/migrate', self::class, 'migrate');
	}

	// посмотреть все активные схемы
	public static function schema(): array {
		return [];
	}

	// посмотреть конкретную схему
	public static function table(string $table): array {
		return [];
	}

	// запустить автомиграцию
	public static function migrate(): array {
		return [];
	}
}