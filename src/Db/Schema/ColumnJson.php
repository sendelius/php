<?php

namespace Sendelius\Db\Schema;

class ColumnJson extends Column {
	protected string $type = 'json';

	public function __construct() {
		$this->default('[]');
	}
}