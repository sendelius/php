<?php

namespace Sendelius\Session;

use Sendelius\Infrastructure\Model;

/**
 * @table sessions
 */
class SessionModel extends Model {
	/**
	 * @column char
	 * @length 36
	 * @primary
	 * @uuid
	 */
	public mixed $id;

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
}