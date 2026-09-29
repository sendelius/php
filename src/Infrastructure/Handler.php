<?php

namespace Sendelius\Infrastructure;

use Sendelius\Http\Request;
use Sendelius\Http\Response;
use Sendelius\Logger\Logger;
use Sendelius\Progress\Progress;
use Sendelius\Queue\Container;
use Sendelius\Queue\Process;

abstract class Handler {
	protected function __construct(
		protected Response $response,
		protected Request  $request,
		protected ?array   $session,
	) {
	}

	protected function progress(?string $id = null): Progress {
		return new Progress(
			id: $id,
		);
	}

	protected function log(string $name, mixed $data, bool $append = true): void {
		Logger::write($name, $data, $append);
	}

	protected function queue(): Process {
		return Container::process();
	}
}