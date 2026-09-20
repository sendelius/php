<?php

namespace Sendelius\Redis;

use JsonException;
use Redis as RedisClient;
use RedisException;
use RuntimeException;
use Sendelius\Config\Env;

class Redis {
	private static RedisClient $redis;
	private static bool $connect = false;

	public function __construct(
		private ?string $prefix = null,
	) {
		if (!self::$connect) {
			try {
				$host = Env::string('REDIS_HOST', '127.0.0.1');
				$port = Env::int('REDIS_PORT', 6379);

				$envPrefix = Env::string('REDIS_PREFIX');
				$prefixes = array_filter([$envPrefix, $prefix], static fn(?string $value): bool => !empty($value));
				$this->prefix = $prefixes ? implode(':', array_map(static fn(string $value): string => trim($value, ':'), $prefixes)) . ':' : '';

				self::$redis = new RedisClient();
				self::$redis->connect($host, $port);
				self::$connect = true;
			} catch (RedisException $e) {
				throw new RuntimeException("ошибка подключения к redis: " . $e->getMessage(), 0, $e);
			}
		}
	}

	private function key(string $key): string {
		return $this->prefix . $key;
	}

	public function get(string $key): mixed {
		$value = self::$redis->get($this->key($key));
		if ($value === false || $value === null) {
			return $value;
		}
		if (json_validate($value)) {
			$decoded = json_decode($value, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}
		return $value;
	}

	public function set(string $key, mixed $value, ?int $ttl = null, bool $onlyIfNotExists = false): bool {
		if (is_array($value) || is_object($value)) {
			try {
				$value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			} catch (JsonException $e) {
				throw new RuntimeException("ошибка обработки json: " . $e->getMessage(), 0, $e);
			}
		}
		$options = [];
		if ($ttl !== null) {
			$options['EX'] = $ttl;
		}
		if ($onlyIfNotExists) {
			$options['NX'] = true;
		}
		if ($options !== []) {
			return self::$redis->set($this->key($key), $value, $options);
		}
		return self::$redis->set($this->key($key), $value);
	}

	public function eval(string $script, array $keys = [], array $args = []): mixed {
		$keys = array_map(fn(string $key): string => $this->key($key), $keys);
		return self::$redis->eval($script, [...$keys, ...$args], count($keys));
	}

	public function delete(string $key): bool {
		return self::$redis->del($this->key($key)) > 0;
	}

	public function exists(string $key): bool {
		return self::$redis->exists($this->key($key)) > 0;
	}

	public function expire(string $key, int $seconds): bool {
		return self::$redis->expire($this->key($key), $seconds);
	}

	public function ttl(string $key): int {
		return self::$redis->ttl($this->key($key));
	}

	public function increment(string $key, int $value = 1): int {
		return self::$redis->incrBy($this->key($key), $value);
	}

	public function decrement(string $key, int $value = 1): int {
		return self::$redis->decrBy($this->key($key), $value);
	}

	public function flush(): bool {
		if ($this->prefix === '') {
			return self::$redis->flushDB();
		}

		$iterator = null;
		do {
			$keys = self::$redis->scan(
				$iterator,
				$this->prefix . '*',
				1000
			);
			if ($keys !== false && $keys !== []) {
				self::$redis->del($keys);
			}
		} while ($iterator !== 0);

		return true;
	}
}