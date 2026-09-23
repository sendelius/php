<?php

namespace Sendelius\Queue;

use Ramsey\Uuid\Uuid;

final class Register extends Resources {
	// Зарегистрировать постоянную задачу
	public function schedule(string $name, string $schedule, callable $handler): void {
		$this->handler($name, $handler);
		$job = $this->table()->where(['name' => $name, 'permanent' => 1])->get();
		if (!$job) {
			$this->table()->insert([
				'id' => Uuid::uuid4()->toString(),
				'name' => $name,
				'queue' => $name,
				'permanent' => 1,
				'schedule' => $schedule,
				'available_at' => $this->nextSchedule($schedule),
			]);
			return;
		}
		if ($job['schedule'] !== $schedule) {
			$this->table()->where(['id' => $job['id']])->update([
				'schedule' => $schedule,
				'available_at' => $this->nextSchedule($schedule),
			]);
		}
	}

	// Зарегистрировать обработчик
	public function handler(string $queue, callable $handler, int $interval = 0): void {
		Container::setHandler($queue, $handler, $interval);
	}
}