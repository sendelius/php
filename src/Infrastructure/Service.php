<?php

namespace Sendelius\Infrastructure;

use Sendelius\Db\Manticore;
use Sendelius\Db\MySQL;
use Sendelius\Db\PostgreSQL;
use Sendelius\Logger\Logger;
use Sendelius\Progress\Progress;
use Sendelius\Redis\Redis;

class Service {
	public function mysql(string $table): MySQL {
		return (new MySQL())->table($table);
	}

	public function postgresql(string $table): PostgreSQL {
		return (new PostgreSQL())->table($table);
	}

	public function manticore(string $table): Manticore {
		return (new Manticore())->table($table);
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