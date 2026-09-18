<?php

namespace Sendelius\Infrastructure;

use Sendelius\Http\Request;
use Sendelius\Http\Response;

class Handler {
	public function __construct(
		public Response $response,
		public Request  $request,
		public ?array   $session,
	) {
	}
}