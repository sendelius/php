<?php

namespace Sendelius\Db\Schema;

use InvalidArgumentException;

abstract class Column {
	protected string $type = 'char';
	protected bool $primary = false;
	protected bool $autoIncrement = false;
	protected bool $autoStringId = false;
	protected mixed $length = null;
	protected bool $nullable = false;
	protected mixed $default = null;
	protected bool $hasDefault = false;
	protected ?string $onUpdate = null;


	protected bool $unique = false;
	protected bool $index = false;

	public function primary(): static {
		$this->primary = true;
		if (in_array($this->type, ['int', 'bigint'])) {
			$this->autoIncrement = true;
		} elseif (in_array($this->type, ['varchar', 'char'])) {
			$this->autoStringId = true;
		}
		return $this;
	}

	public function default(mixed $value): static {
		$this->default = $value;
		$this->hasDefault = true;
		return $this;
	}

	public function onUpdate(string $value): static {
		$this->onUpdate = $value;
		return $this;
	}

	public function unique(): static {
		$this->unique = true;
		return $this;
	}

	public function index(): static {
		$this->index = true;
		return $this;
	}
}