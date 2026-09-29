<?php

namespace Sendelius\Db\Schema;

class ColumnBoolean extends Column {
	protected string $type = 'boolean';

	public function __construct() {
		$this->default(false);
	}
}