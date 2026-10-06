<?php

namespace Sendelius\System;

use Sendelius\Db\Migrate;
use Sendelius\Db\RegistryModel;
use Sendelius\Infrastructure\Handler;

final class SystemHandler extends Handler {
	public function schema(): void {
		$result = [];
		foreach (RegistryModel::all() as $class) {
			$model = new $class();
			$result[$model->table()] = $model->columns();
		}
		$this->response->result($result);
	}

	public function table(): void {
		$table = $this->request->param('table');
		$model = RegistryModel::get($table);
		if (!$model) {
			$this->response->status(404);
			$this->response->error('схема не найдена');
		}
		$this->response->result([
			'table' => $model->table(),
			'columns' => $model->columns(),
		]);
	}

	public function migrate(): void {
		(new Migrate())->run();
	}
}