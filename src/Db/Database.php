<?php

namespace Sendelius\Db;

use PDO;
use PDOException;
use RuntimeException;
use Sendelius\Config\Env;
use Throwable;

class Database {
	private static PDO $pdo;
	private static bool $connect = false;
	private string $tableName = 'snd_table';

	private array $pieces = [
		'where' => [],
		'limit' => null,
		'order' => null,
		'keys' => [],
		'data' => [],
		'selectColumns' => [],
		'pagination' => false,
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

	public function pagination(int $page = 1, int $limit = 100): self {
		$page = max(1, $page);
		$limit = min(max(1, $limit), 1000);
		$this->pieces['pagination'] = ['page' => $page, 'limit' => $limit];
		return $this;
	}

	public function list(array $columns = []): array {
		$this->pieces['selectColumns'] = $columns;
		$pagination = $this->pieces['pagination'];
		$total = 0;
		$paginationData = null;
		if ($pagination) {
			$pieces = $this->pieces;
			$total = $this->count();
			$this->pieces = $pieces;
			$page = $pagination['page'];
			$limit = $pagination['limit'];
			$last = $total > 0 ? (int)ceil($total / $limit) : 0;
			if ($last > 0 && $page > $last) {
				$page = $last;
			}
			$this->limit($limit, ($page - 1) * $limit);
			$paginationData = [
				'page' => $page,
				'limit' => $limit,
				'last' => $last,
			];
			if ($page < $last) {
				$paginationData['next'] = $page + 1;
			}
			if ($page > 1) {
				$paginationData['prev'] = $page - 1;
			}
		}
		$items = $this->buildQuery('select', 'all') ?: [];
		if (!$pagination) {
			return $items;
		}
		return ['items' => $items, 'total' => $total, 'pagination' => $paginationData];
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

	public function count(): int {
		$result = $this->buildQuery('count', 'one');
		return (int)($result['count'] ?? 0);
	}

	public function custom(string $sql, array $data = [], string $fetch = 'none'): mixed {
		return $this->query($sql, $data, $fetch);
	}

	public function multiInsert(array $rows, int $chunkSize = 1000): int {
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
					throw new RuntimeException('ошибка базы данных: структура строк для multiInsert должна быть одинаковой');
				}
				$placeholders = [];
				foreach ($columns as $column) {
					$key = ':insert_' . $index . '_' . $column;
					$placeholders[] = $key;
					$data[$key] = $row[$column];
				}
				$values[] = '(' . implode(',', $placeholders) . ')';
			}
			$sql = sprintf(
				'INSERT INTO %s (%s) VALUES %s',
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

	public function multiUpdate(array $rows, int $chunkSize = 1000): int {
		if (empty($rows)) {
			return 0;
		}
		if ($chunkSize < 1) {
			throw new RuntimeException('ошибка базы данных: размер сегмента должен быть больше 0');
		}
		$total = 0;
		foreach (array_chunk($rows, $chunkSize) as $chunk) {
			$total += $this->transaction(function () use ($chunk) {
				$count = 0;
				foreach ($chunk as $row) {
					if (empty($row)) {
						continue;
					}
					$key = array_key_first($row);
					$value = $row[$key];
					$data = $row;
					unset($data[$key]);
					if (empty($data)) {
						continue;
					}
					if ($this->where([$key => $value])->update($data)) {
						$count++;
					}
				}
				return $count;
			});
		}
		return $total;
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

	public function filter(array $filters, array $fields = []): self {
		foreach ($filters as $field => $value) {
			if (!empty($fields) && !in_array($field, $fields, true)) {
				continue;
			}
			$this->where([$field => $value]);
		}
		return $this;
	}

	public function sort(string $field, string $type = 'asc', array $allowFields = []): self {
		if (!empty($allowFields) && !in_array($field, $allowFields, true)) {
			return $this;
		}
		$type = strtolower($type) === 'desc' ? 'DESC' : 'ASC';
		$this->pieces['order'] = "$field $type";
		return $this;
	}

	public function search(string $value, string $field = '', array $allowFields = []): self {
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

	public function transaction(callable $callback): mixed {
		self::$pdo->beginTransaction();
		try {
			$result = $callback($this);
			self::$pdo->commit();
			return $result;
		} catch (Throwable $e) {
			if (self::$pdo->inTransaction()) {
				self::$pdo->rollBack();
			}
			throw new RuntimeException("ошибка транзакции: " . $e->getMessage(), 0, $e);
		}
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
			'order' => null,
			'keys' => [],
			'data' => [],
			'selectColumns' => [],
			'pagination' => false,
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