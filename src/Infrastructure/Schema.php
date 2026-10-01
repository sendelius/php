<?php

namespace Sendelius\Infrastructure;

use ReflectionClass;
use ReflectionNamedType;
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

	public function columns(): array {
		$columns = [];
		$reflection = new ReflectionClass($this);
		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			$doc = $property->getDocComment();
			if (!$doc || !$this->annotation($doc, 'column')) {
				continue;
			}
			$columns[$this->columnName($property->getName())] = $this->column($property, $doc);
		}
		return $columns;
	}

	public function hydrate(array $data): static {
		$reflection = new ReflectionClass($this);
		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			$name = $property->getName();
			$column = $this->columnName($name);
			if (!array_key_exists($column, $data)) {
				continue;
			}
			$value = $data[$column];
			$type = $property->getType();
			if ($value !== null && $type instanceof ReflectionNamedType) {
				$value = match ($type->getName()) {
					'int' => (int)$value,
					'float' => (float)$value,
					'bool' => (bool)$value,
					'array' => is_string($value) ? json_decode($value, true) : $value,
					'string' => (string)$value,
					default => $value,
				};
			}
			$property->setValue($this, $value);
		}
		return $this;
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
		if ($this->hasAnnotation($doc, 'unique')) {
			$column['unique'] = true;
		}
		if ($this->annotation($doc, 'uuid')) {
			$column['uuid'] = true;
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
		if ($comment = $this->annotation($doc, 'comment')) {
			$column['comment'] = $comment;
		}
		if ($column['type'] === 'json' && !array_key_exists('default', $column)) {
			$column['default'] = '[]';
		}
		if (isset($column['primary']) and $column['primary'] && in_array($column['type'], ['int', 'bigint'], true)) {
			$column['autoIncrement'] = true;
		}
		if ($column['type'] === 'decimal' && isset($column['length'])) {
			$column['length'] = str_replace('.', ',', $column['length']);
		}
		$column['nullable'] = $property->getType()?->allowsNull() ?? false;
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