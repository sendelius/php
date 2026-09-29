<?php

namespace Sendelius\Db;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use Ramsey\Uuid\Uuid;
use Sendelius\Db\Schema\Registry;

abstract class Database {
	protected static PDO $pdo;
	protected static bool $connect = false;
	protected string $tableName = 'snd_table';
	protected array $pieces = [
		'where' => [],
		'limit' => null,
		'order' => null,
		'keys' => [],
		'data' => [],
		'selectColumns' => [],
		'pagination' => false,
	];
	protected static array $log = [];

	protected function connect(string $dsn, string $username = 'root', string $password = ''): void {
		if (!self::$connect) {
			try {
				static::$pdo = new PDO(
					dsn: $dsn,
					username: $username,
					password: $password,
				);
				static::$connect = true;
			} catch (PDOException $e) {
				throw new RuntimeException("ошибка подключения к базе данных: " . $e->getMessage(), 0, $e);
			}
		}
	}

	public function table(string $table): static {
		$this->tableName = $table;
		return $this;
	}

	public function pagination(int $page = 1, int $limit = 100): static {
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

	public function insert(array $data): int|string {
		if (count($data) === 0) {
			return 0;
		}

		$data = $this->prepareAutoIds($data);
		$this->data($data, 'insert');

		if (!$this->buildQuery('insert')) {
			return 0;
		}

		foreach ($this->schema() as $column => $definition) {
			if ($definition->autoStringId()) {
				return $data[$column];
			}
		}

		return intval(static::$pdo->lastInsertId());
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
		$sql = str_replace('{table}', $this->tableName, $sql);
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
			$chunk = array_map(
				fn(array $row) => $this->prepareAutoIds($row),
				$chunk
			);
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
			$sql = $this->buildMultiInsertQuery($columns, $values);
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

	public function where(array $conditions, array $allowFields = []): static {
		foreach ($conditions as $field => $condition) {
			$parts = explode(' ', trim($field), 2);
			$field = $parts[0];
			if (!empty($allowFields) && !in_array($field, $allowFields, true)) {
				return $this;
			}
			$operator = strtoupper($parts[1] ?? '=');
			if (!in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'IN', 'NOT IN'], true)) {
				throw new RuntimeException("ошибка базы данных: неизвестный оператор '$operator'");
			}
			if (in_array($operator, ['IN', 'NOT IN'], true)) {
				if (!is_array($condition) || empty($condition)) {
					continue;
				}
				$keys = [];
				foreach ($condition as $index => $value) {
					$key = ':where_' . $field . '_' . $index;
					$this->pieces['keys'][$field . '_' . $index] = $key;
					$this->pieces['data'][$key] = $value;
					$keys[] = $key;
				}
				$this->pieces['where'][] = "$field $operator (" . implode(',', $keys) . ")";
				continue;
			}
			$this->data([$field => $condition], 'where');
			$key = $this->pieces['keys'][$field] ?? null;
			$this->pieces['where'][] = "$field $operator $key";
		}
		return $this;
	}

	public function limit(int $rows = 0, int $offset = 0): static {
		if ($offset > 0) $this->pieces['limit'] = "LIMIT " . $offset . "," . $rows;
		else $this->pieces['limit'] = "LIMIT " . $rows;
		return $this;
	}

	public function sort(string $field, string $type = 'asc', array $allowFields = []): static {
		if (!empty($allowFields) && !in_array($field, $allowFields, true)) {
			return $this;
		}
		$type = strtolower($type) === 'desc' ? 'DESC' : 'ASC';
		$this->pieces['order'] = "$field $type";
		return $this;
	}

	public function transaction(callable $callback): mixed {
		static::$pdo->beginTransaction();
		try {
			$result = $callback($this);
			static::$pdo->commit();
			return $result;
		} catch (Throwable $e) {
			if (static::$pdo->inTransaction()) {
				static::$pdo->rollBack();
			}
			throw new RuntimeException("ошибка транзакции: " . $e->getMessage(), 0, $e);
		}
	}

	public function getLastQuery(): string {
		$log = $this->getLastLog();
		return (isset($log['sql'])) ? $log['sql'] : '';
	}

	public function getLastLog(): array {
		return (count(static::$log)) ? end(static::$log) : [];
	}

	public function getLog(): array {
		return static::$log;
	}

	protected function data(array $data = [], string $prefix = 'data'): void {
		$firstKeys = array_keys($data);
		$keys = array_keys($data);
		$keys = preg_replace('/^/', ':' . $prefix . '_', $keys, 1);
		$data = array_combine($keys, $data);
		$newKeys = array_combine($firstKeys, array_keys($data));

		$this->pieces['keys'] = array_merge($this->pieces['keys'], $newKeys);
		$this->pieces['data'] = array_merge($this->pieces['data'], $data);
	}

	protected function clearPieces(): void {
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

	protected function query(string $sql, array $data = [], string $fetch = 'none'): mixed {
		try {
			$sqlObj = static::$pdo->prepare($sql);
			foreach ($data as $key => $value) {
				$type = match (true) {
					is_int($value) => PDO::PARAM_INT,
					is_bool($value) => PDO::PARAM_BOOL,
					is_null($value) => PDO::PARAM_NULL,
					default => PDO::PARAM_STR,
				};
				$sqlObj->bindValue($key, $value, $type);
			}
			$result = $sqlObj->execute();
			if ($result && $fetch == 'all') {
				$result = $sqlObj->fetchAll(PDO::FETCH_ASSOC);
			} elseif ($result && $fetch == 'one') {
				$result = $sqlObj->fetch(PDO::FETCH_ASSOC);
			}
			static::$log[] = [
				'sql' => $sql,
				'data' => $data,
			];
			return $result;
		} catch (PDOException $e) {
			throw new RuntimeException("ошибка базы данных: " . $e->getMessage(), 0, $e);
		}
	}

	protected function schema(): array {
		return Registry::get($this->tableName)?->schema() ?? [];
	}

	protected function prepareAutoIds(array $data): array {
		foreach ($this->schema() as $column => $definition) {
			if ($definition->autoStringId() && !array_key_exists($column, $data)) {
				$data[$column] = Uuid::uuid4()->toString();
			}
		}
		return $data;
	}

	abstract protected function buildQuery(string $type, string $fetch = 'none'): mixed;

	abstract protected function buildMultiInsertQuery(array $columns, array $values): string;

	abstract public function search(string $value, string $field = '', array $allowFields = []): static;
}