<?php

namespace Sendelius\System;

use Sendelius\Db\Migrate;
use Sendelius\Db\RegistrySchema;
use Sendelius\Infrastructure\Handler;

final class SystemHandler extends Handler {
	public function schema(): void {
		$result = [];
		foreach (RegistrySchema::all() as $class) {
			$schema = new $class();
			$result[$schema->table()] = $schema->columns();
		}
		$this->response->result($result);
	}

	public function table(): void {
		$table = $this->request->param('table');
		$schema = RegistrySchema::get($table);
		if (!$schema) {
			$this->response->status(404);
			$this->response->error('схема не найдена');
		}
		$this->response->result([
			'table' => $schema->table(),
			'columns' => $schema->columns(),
		]);
	}

	public function migrate(): void {
		(new Migrate())->run();
	}
}