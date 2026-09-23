<?php

namespace Sendelius\Queue;

use RuntimeException;
use Sendelius\Config\Env;
use Sendelius\Db\MySQL;
use Sendelius\Db\PostgreSQL;
use Sendelius\Redis\Redis;

class Resources {
	protected function table(): MySQL|PostgreSQL {
		return match (Env::string('QUEUE_DB_TYPE', 'mysql')) {
			'postgresql' => (new PostgreSQL())->table(Env::string('QUEUE_DB_TABLE', 'queue')),
			default => (new MySQL())->table(Env::string('QUEUE_DB_TABLE', 'queue')),
		};
	}

	protected function redis(): Redis {
		return new Redis(
			prefix: 'queue',
		);
	}

	protected function nextSchedule(string $schedule): string {
		$parts = preg_split('/\s+/', trim($schedule));
		if (count($parts) !== 5) {
			throw new RuntimeException("некорректное расписание: $schedule");
		}
		[$minute, $hour, $day, $month, $weekday] = $parts;
		$time = time() + 60;
		for ($i = 0; $i < 366 * 24 * 60; $i++, $time += 60) {
			$date = getdate($time);
			if (
				!$this->cronMatch($date['minutes'], $minute, 0, 59) ||
				!$this->cronMatch($date['hours'], $hour, 0, 23) ||
				!$this->cronMatch($date['mon'], $month, 1, 12)
			) {
				continue;
			}
			$dayMatch = $this->cronMatch($date['mday'], $day, 1, 31);
			$weekdayMatch = $this->cronMatch($date['wday'], $weekday, 0, 6);
			if ($day === '*' && $weekday === '*') {
				$dateMatch = true;
			} elseif ($day === '*') {
				$dateMatch = $weekdayMatch;
			} elseif ($weekday === '*') {
				$dateMatch = $dayMatch;
			} else {
				$dateMatch = $dayMatch || $weekdayMatch;
			}
			if (!$dateMatch) {
				continue;
			}
			return date('Y-m-d H:i:s', $time);
		}
		throw new RuntimeException("не удалось вычислить следующее выполнение: $schedule");
	}

	protected function cronMatch(int $value, string $expression, int $min, int $max): bool {
		foreach (explode(',', $expression) as $part) {
			if (str_contains($part, '/')) {
				[$range, $step] = explode('/', $part, 2);
				$step = (int)$step;
				if ($step < 1) {
					return false;
				}
				if ($range === '*') {
					$start = $min;
					$end = $max;
				} elseif (str_contains($range, '-')) {
					[$start, $end] = array_map('intval', explode('-', $range, 2));
				} else {
					$start = (int)$range;
					$end = $max;
				}
				if ($value >= $start && $value <= $end && ($value - $start) % $step === 0) {
					return true;
				}
				continue;
			}
			if ($part === '*') {
				return true;
			}
			if (str_contains($part, '-')) {
				[$start, $end] = array_map('intval', explode('-', $part, 2));
				if ($value >= $start && $value <= $end) {
					return true;
				}
				continue;
			}
			if ((int)$part === $value) {
				return true;
			}
		}
		return false;
	}
}