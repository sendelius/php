<?php

namespace Sendelius\Db\Schema;

class ColumnDecimal extends Column {
	protected string $type = 'decimal';

	public function __construct() {
		$this->default(0.00);
		$this->length(10.2);
	}

	public function length(float $length): static {
		$this->length = $length;
		return $this;
	}
}