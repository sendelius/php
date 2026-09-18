<?php

namespace Sendelius\Redis;

use Redis as RedisClient;
use RedisException;
use RuntimeException;
use Sendelius\Config\Env;

class Redis {
	private static RedisClient $redis;
	private static bool $connect = false;
	private string $prefix;

	public function __construct(
		?string $prefix = null,
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
		return self::$redis->get($this->key($key));
	}

	public function set(string $key, mixed $value, ?int $ttl = null): bool {
		$key = $this->key($key);
		if ($ttl !== null) {
			return self::$redis->setex($key, $ttl, $value);
		}
		return self::$redis->set($key, $value);
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

	public function raw(): RedisClient {
		return self::$redis;
	}
}