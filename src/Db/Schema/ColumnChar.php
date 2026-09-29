<?php

namespace Sendelius\Db\Schema;

use RuntimeException;

class ColumnChar extends Column {
	protected string $type = 'char';

	public function __construct() {
		$this->nullable();
	}

	public function var(): static {
		$this->type = 'varchar';
		return $this;
	}

	public function text(): static {
		$this->type = 'text';
		return $this;
	}

	public function medium(): static {
		$this->type = 'mediumtext';
		return $this;
	}

	public function long(): static {
		$this->type = 'longtext';
		return $this;
	}

	public function length(int $length): static {
		if ($length < 1) {
			throw new RuntimeException('длина колонки должна быть больше 0');
		}
		$this->length = $length;
		return $this;
	}

	public function nullable(bool $nullable = true): static {
		$this->nullable = $nullable;
		if ($nullable && empty($this->default)) $this->default(null);
		return $this;
	}
}