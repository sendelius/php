<?php

namespace Sendelius\Db;

use Sendelius\Config\Env;
use Sendelius\Infrastructure\Schema;
use RuntimeException;

final class Migrate {
	public function run(): void {
		$mysql = (Env::string('MYSQL_HOST', '') !== '') ? new MySQL() : null;
		$postgresql = (Env::string('POSTGRESQL_HOST', '') !== '') ? new PostgreSQL() : null;
		if (!$mysql and !$postgresql) {
			throw new RuntimeException('для использования миграции базы нужно чтобы было настроено подключение к базе');
		}
		foreach (RegistrySchema::all() as $class) {
			$schema = new $class();
			if ($mysql) $this->mysql($mysql, $schema);
			if ($postgresql) $this->postgresql($postgresql, $schema);
		}
	}

	private function mysql(MySQL $db, Schema $schema): void {
		$table = $schema->table();
		$columns = $schema->columns();
		if (!$this->mysqlTableExists($db, $table)) {
			$definitions = [];
			foreach ($columns as $name => $definition) {
				$definitions[] = $this->mysqlColumn($db, $name, $definition);
			}
			$db->custom("CREATE TABLE " . $this->mysqlIdentifier($table) . " (" . implode(', ', $definitions) . ")");
			$this->mysqlIndexes($db, $table, $columns);
			return;
		}
		$existing = $db->custom(
			'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table',
			[':table' => $table],
			'all'
		);
		$existing = array_column($existing, null, 'COLUMN_NAME');
		foreach ($columns as $name => $definition) {
			if (!isset($existing[$name])) {
				$db->custom('ALTER TABLE ' . $this->mysqlIdentifier($table) . ' ADD COLUMN ' . $this->mysqlColumn($db, $name, $definition));
				continue;
			}
			$db->custom('ALTER TABLE ' . $this->mysqlIdentifier($table) . ' MODIFY COLUMN ' . $this->mysqlColumn($db, $name, $definition));
		}
		$this->mysqlIndexes($db, $table, $columns);
	}

	private function postgresql(PostgreSQL $db, Schema $schema): void {
		$table = $schema->table();
		$columns = $schema->columns();
		if (!$this->postgresqlTableExists($db, $table)) {
			$definitions = [];
			foreach ($columns as $name => $definition) {
				$definitions[] = $this->postgresqlColumn($db, $name, $definition);
			}
			$db->custom('CREATE TABLE ' . $this->postgresqlIdentifier($table) . ' (' . implode(', ', $definitions) . ')');
			$this->postgresqlIndexes($db, $table, $columns);
			return;
		}
		$existing = $db->custom(
			'SELECT column_name, data_type, udt_name, is_nullable, column_default FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = :table',
			[':table' => $table],
			'all'
		);
		$existing = array_column($existing, null, 'column_name');
		foreach ($columns as $name => $definition) {
			if (!isset($existing[$name])) {
				$db->custom('ALTER TABLE ' . $this->postgresqlIdentifier($table) . ' ADD COLUMN ' . $this->postgresqlColumn($db, $name, $definition));
				continue;
			}
			$this->postgresqlModifyColumn($db, $table, $name, $definition);
		}
		$this->postgresqlIndexes($db, $table, $columns);
	}

	private function mysqlColumn(MySQL $db, string $name, array $definition): string {
		$type = match ($definition['type']) {
			'char' => 'CHAR(' . ($definition['length'] ?? 255) . ')',
			'varchar' => 'VARCHAR(' . ($definition['length'] ?? 255) . ')',
			'text' => 'TEXT',
			'tiny' => 'TINYINT',
			'small' => 'SMALLINT',
			'int' => 'INT',
			'bigint' => 'BIGINT',
			'bool' => 'TINYINT(1)',
			'json' => 'JSON',
			'date' => 'DATETIME',
			'enum' => 'ENUM(' . implode(',', array_map(fn(string $value) => $db->quote($value), $definition['options'] ?? [])) . ')',
			'decimal' => 'DECIMAL(' . ($definition['length'] ?? '10,2') . ')',
			default => throw new RuntimeException(
				"неизвестный тип колонки: {$definition['type']}"
			),
		};
		$nullable = !empty($definition['nullable']) ? ' NULL' : ' NOT NULL';
		$default = $this->defaultValue($db, $definition['default'] ?? null);
		$onUpdate = !empty($definition['onUpdate']) ? ' ON UPDATE ' . $definition['onUpdate'] : '';
		$primary = !empty($definition['primary']) ? ' PRIMARY KEY' : '';
		return $this->mysqlIdentifier($name) . " $type$nullable$default$onUpdate$primary";
	}

	private function postgresqlColumn(PostgreSQL $db, string $name, array $definition): string {
		$type = match ($definition['type']) {
			'char' => 'CHAR(' . ($definition['length'] ?? 255) . ')',
			'varchar' => 'VARCHAR(' . ($definition['length'] ?? 255) . ')',
			'text' => 'TEXT',
			'tiny' => 'SMALLINT',
			'small' => 'SMALLINT',
			'int' => 'INTEGER',
			'bigint' => 'BIGINT',
			'bool' => 'BOOLEAN',
			'json' => 'JSONB',
			'date' => 'TIMESTAMP',
			'enum' => 'VARCHAR(255)',
			'decimal' => 'DECIMAL(' . ($definition['length'] ?? '10,2') . ')',
			default => throw new RuntimeException(
				"неизвестный тип колонки: {$definition['type']}"
			),
		};
		$nullable = !empty($definition['nullable']) ? ' NULL' : ' NOT NULL';
		$default = $this->defaultValue($db, $definition['default'] ?? null);
		$primary = !empty($definition['primary']) ? ' PRIMARY KEY' : '';
		return $this->postgresqlIdentifier($name) . " $type$nullable$default$primary";
	}

	private function mysqlIndexes(MySQL $db, string $table, array $columns): void {
		$indexes = $db->custom(
			'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table',
			[':table' => $table],
			'all'
		);
		$existing = [];
		foreach ($indexes as $index) {
			$existing[$index['INDEX_NAME']] = true;
		}
		foreach ($columns as $name => $definition) {
			if (!empty($definition['index'])) {
				$index = "idx_{$table}_{$name}";
				if (!isset($existing[$index])) {
					$db->custom('CREATE INDEX ' . $this->mysqlIdentifier($index) . ' ON ' . $this->mysqlIdentifier($table) . ' (' . $this->mysqlIdentifier($name) . ')');
				}
			}
			if (!empty($definition['unique'])) {
				$index = "uniq_{$table}_{$name}";
				if (!isset($existing[$index])) {
					$db->custom('CREATE UNIQUE INDEX ' . $this->mysqlIdentifier($index) . ' ON ' . $this->mysqlIdentifier($table) . ' (' . $this->mysqlIdentifier($name) . ')');
				}
			}
		}
	}

	private function postgresqlIndexes(PostgreSQL $db, string $table, array $columns): void {
		$indexes = $db->custom(
			'SELECT indexname FROM pg_indexes WHERE schemaname = \'public\' AND tablename = :table',
			[':table' => $table],
			'all'
		);
		$existing = array_flip(array_column($indexes, 'indexname'));
		foreach ($columns as $name => $definition) {
			if (!empty($definition['index'])) {
				$index = "idx_{$table}_{$name}";
				if (!isset($existing[$index])) {
					$db->custom('CREATE INDEX ' . $this->postgresqlIdentifier($index) . ' ON ' . $this->postgresqlIdentifier($table) . ' (' . $this->postgresqlIdentifier($name) . ')');
				}
			}
			if (!empty($definition['unique'])) {
				$index = "uniq_{$table}_{$name}";
				if (!isset($existing[$index])) {
					$db->custom('CREATE UNIQUE INDEX ' . $this->postgresqlIdentifier($index) . ' ON ' . $this->postgresqlIdentifier($table) . ' (' . $this->postgresqlIdentifier($name) . ')');
				}
			}
		}
	}

	private function mysqlTableExists(MySQL $db, string $table): bool {
		$result = $db->custom(
			'SELECT COUNT(*) AS count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
			[':table' => $table],
			'one'
		);
		return (int)$result['count'] > 0;
	}

	private function postgresqlTableExists(PostgreSQL $db, string $table): bool {
		$result = $db->custom(
			'SELECT EXISTS ( SELECT 1 FROM information_schema.tables WHERE table_schema = \'public\' AND table_name = :table ) AS exists',
			[':table' => $table],
			'one'
		);
		return filter_var($result['exists'], FILTER_VALIDATE_BOOLEAN);
	}

	private function postgresqlModifyColumn(PostgreSQL $db, string $table, string $name, array $definition): void {
		$type = match ($definition['type']) {
			'char' => 'CHAR(' . ($definition['length'] ?? 255) . ')',
			'varchar' => 'VARCHAR(' . ($definition['length'] ?? 255) . ')',
			'text' => 'TEXT',
			'tiny', 'small' => 'SMALLINT',
			'int' => 'INTEGER',
			'bigint' => 'BIGINT',
			'bool' => 'BOOLEAN',
			'json' => 'JSONB',
			'date' => 'TIMESTAMP',
			'enum' => 'VARCHAR(255)',
			'decimal' => 'DECIMAL(' . ($definition['length'] ?? '10,2') . ')',
			default => throw new RuntimeException(
				"неизвестный тип колонки: {$definition['type']}"
			),
		};
		$db->custom('ALTER TABLE ' . $this->postgresqlIdentifier($table) . ' ALTER COLUMN ' . $this->postgresqlIdentifier($name) . " TYPE $type");
		if (!empty($definition['nullable'])) {
			$db->custom('ALTER TABLE ' . $this->postgresqlIdentifier($table) . ' ALTER COLUMN ' . $this->postgresqlIdentifier($name) . ' DROP NOT NULL');
		} else {
			$db->custom('ALTER TABLE ' . $this->postgresqlIdentifier($table) . ' ALTER COLUMN ' . $this->postgresqlIdentifier($name) . ' SET NOT NULL');
		}

		if (array_key_exists('default', $definition)) {
			$default = $this->defaultValue($db, $definition['default']);
			$db->custom('ALTER TABLE ' . $this->postgresqlIdentifier($table) . ' ALTER COLUMN ' . $this->postgresqlIdentifier($name) . ($default === '' ? ' DROP DEFAULT' : " SET$default"));
		}
	}

	private function defaultValue(object $db, mixed $value): string {
		if ($value === null) {
			return '';
		}
		if (strtolower((string)$value) === 'null') {
			return ' DEFAULT NULL';
		}
		if (preg_match('/^[A-Z_][A-Z0-9_]*\(.*\)$/i', $value)) {
			return ' DEFAULT ' . $value;
		}
		return ' DEFAULT ' . $db->quote($value);
	}

	private function mysqlIdentifier(string $name): string {
		return '`' . str_replace('`', '``', $name) . '`';
	}

	private function postgresqlIdentifier(string $name): string {
		return '"' . str_replace('"', '""', $name) . '"';
	}
}