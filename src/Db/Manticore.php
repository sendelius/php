<?php

namespace Sendelius\Db;

use PDO;
use RuntimeException;
use Sendelius\Config\Env;

class Manticore extends Database {
	protected static PDO $pdo;
	protected static bool $connect = false;

	public function __construct() {
		$host = Env::string('MANTICORE_HOST', '127.0.0.1');
		$port = Env::int('MANTICORE_PORT', 9306);
		$db = Env::string('MANTICORE_DB', 'snd_manticore');
		$user = Env::string('MANTICORE_USER', 'root');
		$password = Env::string('MANTICORE_PASSWORD');
		$this->connect(
			dsn: "mysql:dbname=$db;host=$host;port=$port",
			username: $user,
			password: $password,
		);
	}

	public function search(string $value, string $field = '', array $allowFields = []): static {
		if ($value === '') {
			return $this;
		}
		if (empty($allowFields)) {
			$allowFields = ['title'];
		}
		if ($field !== '') {
			$requested = array_map('trim', explode(',', $field));
			$fields = array_values(array_intersect($requested, $allowFields));
		} else {
			$fields = array_slice($allowFields, 0, 1);
		}
		if (empty($fields)) {
			return $this;
		}
		$conditions = [];
		foreach ($fields as $field) {
			$conditions[] = '@' . $field . ' ' . $value;
		}
		$key = ':search';
		$this->pieces['where'][] = 'MATCH(' . $key . ')';
		$this->pieces['data'][$key] = implode(' | ', $conditions);
		return $this;
	}

	public function multiReplace(array $rows, int $chunkSize = 1000): int {
		if (empty($rows)) {
			return 0;
		}
		if ($chunkSize < 1) {
			throw new RuntimeException('ошибка базы данных: размер сегмента должен быть больше 0');
		}
		$total = 0;
		foreach (array_chunk($rows, $chunkSize) as $chunk) {
			$columns = array_keys($chunk[0]);
			$values = [];
			$data = [];
			foreach ($chunk as $index => $row) {
				if (array_keys($row) !== $columns) {
					throw new RuntimeException('ошибка базы данных: структура строк для multiReplace должна быть одинаковой');
				}
				$placeholders = [];
				foreach ($columns as $column) {
					$key = ':replace_' . $index . '_' . $column;
					$placeholders[] = $key;
					$data[$key] = $row[$column];
				}
				$values[] = '(' . implode(',', $placeholders) . ')';
			}
			$sql = sprintf(
				'REPLACE INTO %s (%s) VALUES %s',
				$this->tableName,
				implode(',', $columns),
				implode(',', $values)
			);
			if ($this->query($sql, $data)) {
				$total += count($chunk);
			}
		}
		return $total;
	}

	protected function buildMultiInsertQuery(array $columns, array $values): string {
		return sprintf(
			'INSERT INTO %s (%s) VALUES %s',
			$this->tableName,
			implode(',', $columns),
			implode(',', $values)
		);
	}

	protected function buildQuery(string $type, string $fetch = 'none'): mixed {
		$sql = '';
		switch ($type) {
			case 'select':
				$select = '*';
				if (count($this->pieces['selectColumns']) > 0) {
					$select = implode(', ', $this->pieces['selectColumns']);
				}
				$sql = "SELECT $select FROM {$this->tableName}";
				break;
			case 'insert':
				$sql = sprintf(
					'INSERT INTO %s (%s) VALUES (%s)',
					$this->tableName,
					implode(',', array_keys($this->pieces['keys'])),
					implode(',', array_keys($this->pieces['data']))
				);
				break;
			case 'update':
				if (empty($this->pieces['where'])) {
					throw new RuntimeException("ошибка базы данных: update без условий where невозможен");
				}
				$set = [];
				foreach ($this->pieces['keys'] as $field => $key) {
					if (str_starts_with($key, ':update_')) {
						$set[] = "$field = $key";
					}
				}
				$sql = "UPDATE {$this->tableName} SET " . implode(', ', $set);
				break;
			case 'delete':
				$sql = "DELETE FROM {$this->tableName}";
				break;
			case 'count':
				$sql = "SELECT COUNT(*) AS count FROM {$this->tableName}";
				break;
		}
		if (empty($sql)) return false;
		if (in_array($type, ['select', 'count', 'delete', 'update'])) {
			if (!empty($this->pieces['where'])) {
				$sql .= ' WHERE ' . implode(' AND ', $this->pieces['where']);
			}
			if ($type === 'select' && !empty($this->pieces['order'])) {
				$sql .= ' ORDER BY ' . $this->pieces['order'];
			}
			if (in_array($type, ['select', 'delete', 'update']) && !empty($this->pieces['limit'])) {
				$sql .= ' ' . $this->pieces['limit'];
			}
		}
		try {
			return ($sql) ? $this->query($sql, $this->pieces['data'], $fetch) : false;
		} finally {
			$this->clearPieces();
		}
	}
}