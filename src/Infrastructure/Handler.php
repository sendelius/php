<?php

namespace Sendelius\Infrastructure;

use Sendelius\Http\Request;
use Sendelius\Http\Response;
use Sendelius\Logger\Logger;
use Sendelius\Progress\Progress;
use Sendelius\Queue\Container;
use Sendelius\Queue\Process;

class Handler {
	public function __construct(
		public Response $response,
		public Request  $request,
		public ?array   $session,
	) {
	}

	public function progress(?string $id = null): Progress {
		return new Progress(
			id: $id,
		);
	}

	public function log(string $name, mixed $data, bool $append = true): void {
		Logger::write($name, $data, $append);
	}

	public function queue(): Process {
		return Container::process();
	}
}