<?php

namespace Sendelius\Db\Schema;

class ColumnEnum extends Column {
	protected string $type = 'enum';

	public function __construct() {
	}

	public function options(array $options): static {
		$this->length = implode(',', array_map(
			static fn(string $option): string => "'" . str_replace("'", "''", $option) . "'",
			$options
		));
		return $this;
	}
}