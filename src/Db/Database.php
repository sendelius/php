<?php

namespace Sendelius\Db;

use PDO;
use PDOException;
use RuntimeException;
use Sendelius\Config\Env;

class Database {
	private static PDO $pdo;
	private static bool $connect = false;
	private string $tableName = 'snd_table';

	private array $pieces = [
		'where' => [],
		'limit' => null,
		'keys' => [],
		'data' => [],
		'selectColumns' => [],
	];

	public function __construct() {
		if (!self::$connect) {
			try {
				$driver = Env::string('DATABASE_DRIVER', 'mysql');
				$host = Env::string('DATABASE_HOST', '127.0.0.1');
				$port = Env::int('DATABASE_PORT', 3306);
				$db = Env::string('DATABASE_DB', 'snd_db');
				$user = Env::string('DATABASE_USER', 'root');
				$password = Env::string('DATABASE_PASSWORD');
				$charset = Env::string('DATABASE_CHARSET', 'utf8mb4');
				self::$pdo = new PDO(
					dsn: $driver . ':dbname=' . $db . ';host=' . $host . ';port=' . $port . ';charset=' . $charset,
					username: $user,
					password: $password,
				);
				self::$connect = true;
			} catch (PDOException $e) {
				throw new RuntimeException("ошибка подключения к базе данных: " . $e->getMessage(), 0, $e);
			}
		}
	}

	public function table(string $table): self {
		$this->tableName = $table;
		return $this;
	}

	public function list(array $columns = []): array {
		$this->pieces['selectColumns'] = $columns;
		$result = $this->buildQuery('select', 'all');
		return ($result && is_array($result)) ? array_map([$this, 'prepareResult'], $result) : [];
	}

	public function get(array $columns = []) {
		$this->pieces['selectColumns'] = $columns;
		$this->limit(1);
		return $this->buildQuery('select', 'one');
	}

	public function insert(array $data): int {
		if (count($data) === 0) {
			return 0;
		}

		$this->clearPieces();
		$this->data($data, 'insert');
		$result = $this->buildQuery('insert');
		if ($result) {
			return intval(self::$pdo->lastInsertId());
		} else return 0;
	}

	public function update(array $data): bool {
		if (count($data) === 0) {
			return false;
		}

		$this->data($data, 'update');
		return (bool)$this->buildQuery('update');
	}

	public function delete(): bool {
		return (bool)$this->buildQuery('delete');
	}

	public function where(array $conditions): self {
		foreach ($conditions as $field => $condition) {
			$this->data([$field => $condition], 'where');
			$key = (array_key_exists($field, $this->pieces['keys'])) ? $this->pieces['keys'][$field] : null;
			$this->pieces['where'][] = "$field = $key";
		}
		return $this;
	}

	public function limit(int $rows = 0, int $offset = 0): self {
		if ($offset > 0) $this->pieces['limit'] = "LIMIT " . $offset . "," . $rows;
		else $this->pieces['limit'] = "LIMIT " . $rows;
		return $this;
	}

	private function data(array $data = [], string $prefix = 'data'): void {
		$firstKeys = array_keys($data);
		$keys = array_keys($data);
		$keys = preg_replace('/^/', ':' . $prefix . '_', $keys, 1);
		$data = array_combine($keys, $data);
		$newKeys = array_combine($firstKeys, array_keys($data));

		$this->pieces['keys'] = array_merge($this->pieces['keys'], $newKeys);
		$this->pieces['data'] = array_merge($this->pieces['data'], $data);
	}

	private function clearPieces(): void {
		$this->pieces = [
			'where' => [],
			'limit' => null,
			'keys' => [],
			'data' => [],
		];
	}

	private function buildQuery(string $type, string $fetch = 'none'): mixed {
		$sql = '';

		switch ($type) {
			case 'select':
				$select = '*';
				if (count($this->pieces['selectColumns']) > 0) $select = implode(', ', $this->pieces['selectColumns']);
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
		}

		if (empty($sql)) return false;

		if (in_array($type, ['select', 'delete', 'update'])) {
			if (!empty($this->pieces['where'])) $sql .= ' WHERE ' . implode(' AND ', $this->pieces['where']);
			if (!empty($this->pieces['limit'])) $sql .= ' ' . $this->pieces['limit'];
		}

		return ($sql) ? $this->query($sql, $this->pieces['data'], $fetch) : false;
	}

	private function query(string $sql, array $data = [], string $fetch = 'none'): mixed {
		try {
			$sqlObj = self::$pdo->prepare($sql);
			$result = $sqlObj->execute($data);
			if ($result && $fetch == 'all') {
				$result = $sqlObj->fetchAll(PDO::FETCH_ASSOC);
			} elseif ($result && $fetch == 'one') {
				$result = $sqlObj->fetch(PDO::FETCH_ASSOC);
			}
			return $result;
		} catch (PDOException $e) {
			throw new RuntimeException("ошибка базы данных: " . $e->getMessage(), 0, $e);
		}
	}
}