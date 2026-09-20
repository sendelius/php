<?php

namespace Sendelius\Infrastructure;

use Sendelius\Db\Database;
use Sendelius\Logger\Logger;
use Sendelius\Progress\Progress;
use Sendelius\Redis\Redis;

class Service {
	public function table(string $table): Database {
		return (new Database())->table($table);
	}

	public function redis(?string $prefix = null): Redis {
		return new Redis(
			prefix: $prefix,
		);
	}

	public function progress(?string $id = null): Progress {
		return new Progress(
			id: $id,
		);
	}

	public function log(string $name, mixed $data, bool $append = true): void {
		Logger::write($name, $data, $append);
	}
}