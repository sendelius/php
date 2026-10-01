<?php

namespace Sendelius\Session;

use Sendelius\Infrastructure\Schema;

/**
 * @table sessions
 */
class SessionSchema extends Schema {
	/**
	 * @column char
	 * @length 36
	 * @primary
	 * @uuid
	 */
	public string $id;

	/**
	 * @column char
	 * @length 64
	 * @unique
	 * @index
	 */
	public string $token;

	/**
	 * @column string
	 * @index
	 */
	public string $userId;

	/**
	 * @column int
	 * @index
	 */
	public int $expired;

	/**
	 * @column json
	 */
	public string $request;

	/**
	 * @column date
	 * @default NOW()
	 */
	public string $createdAt;

	/**
	 * @column date
	 * @default NOW()
	 * @onUpdate NOW()
	 */
	public string $updatedAt;
}