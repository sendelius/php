<?php

namespace Sendelius\Infrastructure;

use Sendelius\Db\Database;
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
}