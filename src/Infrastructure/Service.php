<?php

namespace Sendelius\Infrastructure;

use Sendelius\Db\Manticore;
use Sendelius\Db\MySQL;
use Sendelius\Db\PostgreSQL;
use Sendelius\Logger\Logger;
use Sendelius\Progress\Progress;
use Sendelius\Queue\Container;
use Sendelius\Queue\Push;
use Sendelius\Redis\Redis;

abstract class Service {
	protected function mysql(string $table): MySQL {
		return (new MySQL())->table($table);
	}

	protected function postgresql(string $table): PostgreSQL {
		return (new PostgreSQL())->table($table);
	}

	protected function manticore(): Manticore {
		return (new Manticore());
	}

	protected function redis(?string $prefix = null): Redis {
		return new Redis(
			prefix: $prefix,
		);
	}

	protected function progress(?string $id = null): Progress {
		return new Progress(
			id: $id,
		);
	}

	protected function queue(): Push {
		return Container::push();
	}

	protected function log(string $name, mixed $data, bool $append = true): void {
		Logger::write($name, $data, $append);
	}
}