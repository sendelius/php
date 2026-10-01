<?php

namespace Sendelius\Session;

use Sendelius\Db\Registry;

class Session {
	public function __construct() {
		Registry::add(SessionSchema::class);
	}
}