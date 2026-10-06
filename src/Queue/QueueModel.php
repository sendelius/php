<?php

namespace Sendelius\Queue;

use Sendelius\Infrastructure\Model;

class QueueModel extends Model {
	/**
	 * @column char
	 * @length 36
	 * @primary
	 * @uuid
	 */
	public mixed $id;

	/**
	 * @column varchar
	 * @length 100
	 * @index
	 */
	public string $name;

	/**
	 * @column varchar
	 * @length 150
	 * @index
	 */
	public string $queue;

	/**
	 * @column json
	 */
	public array $payload;

	/**
	 * @column char
	 * @length 100
	 * @index
	 */
	public string $storageId;

	/**
	 * @column bool
	 * @index
	 */
	public bool $permanent;

	/**
	 * @column char
	 * @length 100
	 */
	public string $schedule;

	/**
	 * @column enum
	 * @options pending,processing,done,failed
	 * @default pending
	 * @index
	 */
	public string $status;

	/**
	 * @column int
	 */
	public int $attempts;

	/**
	 * @column text
	 */
	public string $error;

	/**
	 * @column date
	 * @index
	 */
	public string $availableAt;

	/**
	 * @column date
	 * @default null
	 */
	public ?string $startedAt;

	/**
	 * @column date
	 * @default null
	 */
	public ?string $finishedAt;
}