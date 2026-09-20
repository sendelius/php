<?php

namespace Sendelius\Infrastructure;

use Sendelius\Http\Request;
use Sendelius\Http\Response;
use Sendelius\Logger\Logger;

class Handler {
	public function __construct(
		public Response $response,
		public Request  $request,
		public ?array   $session,
	) {
	}

	public function log(string $name, mixed $data, bool $append = true): void {
		Logger::write($name, $data, $append);
	}
}