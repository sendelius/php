<?php

namespace Sendelius\Db\Schema;

class ColumnDate extends Column {
	protected string $type = 'datetime';

	public function __construct() {
		$this->default('CURRENT_TIMESTAMP');
	}

	public function nullable(bool $nullable = true): static {
		$this->nullable = $nullable;
		if ($nullable && empty($this->default)) $this->default(null);
		return $this;
	}

	public function default(mixed $value = 'CURRENT_TIMESTAMP'): static {
		$this->default = $value;
		$this->hasDefault = true;
		return $this;
	}
}