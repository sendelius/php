<?php

namespace Sendelius\Infrastructure;

use Sendelius\Db\Schema\ColumnBoolean;
use Sendelius\Db\Schema\ColumnChar;
use Sendelius\Db\Schema\ColumnDate;
use Sendelius\Db\Schema\ColumnDecimal;
use Sendelius\Db\Schema\ColumnEnum;
use Sendelius\Db\Schema\ColumnInt;
use Sendelius\Db\Schema\ColumnJson;

abstract class Schema {
	abstract public function schema(): array;

	public function name(): string {
		$baseName = basename(str_replace('\\', '/', static::class));
		$baseName = preg_replace('/Schema$/', '', $baseName);
		return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $baseName));
	}

	protected function int(): ColumnInt {
		return new ColumnInt();
	}

	protected function decimal(): ColumnDecimal {
		return new ColumnDecimal();
	}

	protected function char(): ColumnChar {
		return new ColumnChar();
	}

	protected function boolean(): ColumnBoolean {
		return new ColumnBoolean();
	}

	protected function json(): ColumnJson {
		return new ColumnJson();
	}

	protected function date(): ColumnDate {
		return new ColumnDate();
	}

	protected function enum(): ColumnEnum {
		return new ColumnEnum();
	}
}