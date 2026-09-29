<?php

namespace Sendelius\Db\Schema;

use RuntimeException;

class ColumnInt extends Column {
	protected string $type = 'int';

	public function __construct() {
		$this->default(0);
	}

	public function small(): static {
		$this->type = 'smallint';
		return $this;
	}

	public function tiny(): static {
		$this->type = 'tinyint';
		return $this;
	}

	public function big(): static {
		$this->type = 'bigint';
		return $this;
	}

	public function length(int $length): static {
		if ($length < 1) {
			throw new RuntimeException('длина колонки должна быть больше 0');
		}
		$this->length = $length;
		return $this;
	}
}