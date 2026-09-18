<?php

namespace Sendelius\Logger;

use Sendelius\Config\Env;

class Logger {
	private static string $directory = '';

	public static function init(): void {
		if (Env::bool('LOGS_WRITE')) {
			self::$directory = APP_DIR . 'logs' . DS;
			if (!is_dir(self::$directory)) {
				mkdir(self::$directory, 0775, true);
			}
		}
	}

	public static function write(string $name, mixed $data, bool $append = true): void {
		if (self::$directory === '') {
			return;
		}
		$file = self::$directory . $name . '.log';
		if (is_array($data)) {
			$data = implode(' | ', array_map(static fn($value) => self::format($value), $data));
		} else {
			$data = self::format($data);
		}
		file_put_contents($file, date('Y-m-d H:i:s') . ' | ' . $data . PHP_EOL, $append ? FILE_APPEND : 0);
	}

	private static function format(mixed $value): string {
		if ($value === null) {
			return '';
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (is_array($value) || is_object($value)) {
			return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		return str_replace(['|', "\r", "\n"], ['\|', '', ''], (string)$value);
	}
}
