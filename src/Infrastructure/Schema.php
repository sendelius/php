<?php

namespace Sendelius\Infrastructure;

use ReflectionClass;
use ReflectionProperty;

abstract class Schema {
	public function table(): string {
		$doc = (new ReflectionClass($this))->getDocComment();
		if ($doc && preg_match('/@table\s+(\S+)/', $doc, $match)) {
			return $match[1];
		}
		$name = basename(str_replace('\\', '/', static::class));
		$name = preg_replace('/Schema$/', '', $name);
		return strtolower(
			preg_replace('/(?<!^)[A-Z]/', '_$0', $name)
		);
	}

	public function schema(): array {
		$schema = [];
		$reflection = new ReflectionClass($this);
		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			$doc = $property->getDocComment();
			if (!$doc || !$this->annotation($doc, 'column')) {
				continue;
			}
			$schema[$this->columnName($property->getName())] = $this->column($property, $doc);
		}
		return $schema;
	}

	protected function column(ReflectionProperty $property, string $doc): array {
		$column = [
			'type' => $this->annotation($doc, 'column'),
		];
		if ($length = $this->annotation($doc, 'length')) {
			$column['length'] = $length;
		}
		if ($this->hasAnnotation($doc, 'primary')) {
			$column['primary'] = true;
		}
		if ($this->hasAnnotation($doc, 'index')) {
			$column['index'] = true;
		}
		if ($options = $this->annotation($doc, 'options')) {
			$column['options'] = array_map('trim', explode(',', $options));
		}
		if (($default = $this->annotation($doc, 'default')) !== null) {
			$column['default'] = $default;
		}
		if ($onUpdate = $this->annotation($doc, 'onUpdate')) {
			$column['onUpdate'] = $onUpdate;
		}
		return $column;
	}

	protected function columnName(string $name): string {
		return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
	}

	protected function annotation(string $doc, string $name): ?string {
		if (!preg_match('/@' . preg_quote($name, '/') . '(?:\s+(.+?))?(?=\s*\*\/|\s*\n|\z)/', $doc, $match)) {
			return null;
		}
		return isset($match[1]) ? trim($match[1]) : '';
	}

	protected function hasAnnotation(string $doc, string $name): bool {
		return preg_match('/@' . preg_quote($name, '/') . '(?:\s|$)/', $doc) === 1;
	}
}