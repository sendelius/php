<?php

namespace Sendelius\Db;

use PDO;
use RuntimeException;
use Sendelius\Config\Env;

class MySQL extends Database {
	protected static PDO $pdo;
	protected static bool $connect = false;

	public function __construct() {
		$host = Env::string('MYSQL_HOST', '127.0.0.1');
		$port = Env::int('MYSQL_PORT', 3306);
		$db = Env::string('MYSQL_DB', 'snd_mysql');
		$user = Env::string('MYSQL_USER', 'root');
		$password = Env::string('MYSQL_PASSWORD');
		$charset = Env::string('MYSQL_CHARSET', 'utf8mb4');
		$this->connect(
			dsn: "mysql:dbname=$db;host=$host;port=$port;charset=$charset",
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
		$data = [];
		foreach ($fields as $index => $field) {
			$key = ':search_' . $index;
			$conditions[] = "$field LIKE $key";
			$data[$key] = '%' . $value . '%';
		}
		$this->pieces['where'][] = '(' . implode(' OR ', $conditions) . ')';
		$this->pieces['data'] = array_merge($this->pieces['data'], $data);
		return $this;
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
				$sqlFormat = "INSERT INTO {$this->tableName} (%s) VALUE (%s)";
				$sql = sprintf(
					$sqlFormat,
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