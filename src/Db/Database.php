<?php

namespace Sendelius\Db;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

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

	public function insert(array $data): int {
		if (count($data) === 0) {
			return 0;
		}
		$this->data($data, 'insert');
		$result = $this->buildQuery('insert');
		if ($result) {
			return intval(static::$pdo->lastInsertId());
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

	public function where(array $conditions): static {
		foreach ($conditions as $field => $condition) {
			$this->data([$field => $condition], 'where');
			$key = (array_key_exists($field, $this->pieces['keys'])) ? $this->pieces['keys'][$field] : null;
			$this->pieces['where'][] = "$field = $key";
		}
		return $this;
	}

	public function limit(int $rows = 0, int $offset = 0): static {
		if ($offset > 0) $this->pieces['limit'] = "LIMIT " . $offset . "," . $rows;
		else $this->pieces['limit'] = "LIMIT " . $rows;
		return $this;
	}

	public function filter(array $filters, array $fields = []): static {
		foreach ($filters as $field => $value) {
			if (!empty($fields) && !in_array($field, $fields, true)) {
				continue;
			}
			$this->where([$field => $value]);
		}
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

	abstract protected function buildQuery(string $type, string $fetch = 'none'): mixed;

	abstract protected function buildMultiInsertQuery(array $columns, array $values): string;

	abstract public function search(string $value, string $field = '', array $allowFields = []): static;
}