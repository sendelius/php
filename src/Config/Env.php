<?php

namespace Sendelius\Config;

class Env {
	public static function string(string $key, string $default = ''): string {
		$value = self::get($key);
		return $value === null ? $default : (string)$value;
	}

	public static function int(string $key, int $default = 0): int {
		$value = self::get($key);
		return $value === null ? $default : (int)$value;
	}

	public static function float(string $key, float $default = 0): float {
		$value = self::get($key);
		return $value === null ? $default : (float)$value;
	}

	public static function bool(string $key, bool $default = false): bool {
		$value = self::get($key);
		if ($value === null) {
			return $default;
		}
		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
	}

	public static function array(string $key, array $default = []): array {
		$value = self::get($key);
		if ($value === null) {
			return $default;
		}
		if (is_array($value)) {
			return $value;
		}
		return array_map('trim', explode(',', (string)$value));
	}

	private static function get(string $key): mixed {
		if (defined('APP_ENV') && APP_ENV !== null) {
			$envKey = strtoupper(APP_ENV) . '_' . $key;
			if (array_key_exists($envKey, $_ENV)) {
				return $_ENV[$envKey];
			}
		}
		return $_ENV[$key] ?? null;
	}
}