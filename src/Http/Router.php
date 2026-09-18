<?php

namespace Sendelius\Http;

use Closure;
use Sendelius\Config\Env;

class Router {
	private array $routes = [];

	public function __construct(
		public Response           $response,
		private readonly ?Closure $protectedCallback = null,
	) {
	}

	public function route(
		string $path,
		string $handler,
		string $action,
		bool   $protected = false,
	): void {
		$method = 'GET';
		if (preg_match('/^(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\s+(.+)$/i', $path, $matches)) {
			$method = strtoupper($matches[1]);
			$path = $matches[2];
		}
		$this->routes[md5($path . $handler . $action)] = [
			'protected' => $protected,
			'method' => $method,
			'path' => $path,
			'handler' => $handler,
			'action' => $action,
		];
	}

	public function start(): void {
		$findRoute = false;
		foreach ($this->routes as $route) {
			$protected = $route['protected'];
			$method = $route['method'];
			$path = $route['path'];
			$handler = $route['handler'];
			$action = $route['action'];

			$pattern = preg_replace_callback(
				'#\{(\w+)(?::(\w+))?}#',
				fn($m) => '(?P<' . $m[1] . '>' . $this->typePattern($m[2] ?? 'string') . ')',
				$path
			);
			$pattern = "#^$pattern$#";
			$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

			if (!$findRoute and $method === $_SERVER['REQUEST_METHOD'] and preg_match($pattern, $uri, $matches) and method_exists($handler, $action)) {

				$session = ($this->protectedCallback !== null) ? ($this->protectedCallback)($route, $this) : null;
				if ($protected and (!$session or empty($session))) {
					$this->response->status(401);
					$this->response->error('доступ запрещен');
				}

				$params = $this->getUriParams($matches, $path);

				$allowEnv = Env::array('ALLOW_ROUTE_ENV');
				$env = $params['env'] ?? null;
				if (isset($params['env'])) unset($params['env']);
				if ($env and count($allowEnv) and !in_array($env, $allowEnv)) {
					$this->response->error('недопустимая среда');
				}
				define('APP_ENV', $env);

				$handler = new $handler(
					response: $this->response,
					request: new Request($params),
					session: ($session and !empty($session)) ? $session : null,
				);
				$handler->$action();
				$findRoute = true;
				break;
			}
		}
		if (!$findRoute) {
			$this->response->status(404);
			$this->response->error('метод не найден');
		}
	}

	private function typePattern(string $type): string {
		return match ($type) {
			'int' => '\d+',
			'float' => '[0-9]+(?:\.[0-9]+)?',
			default => '[^/]+',
		};
	}

	private function convertType(string $value, string $type): string|int|float {
		return match ($type) {
			'int' => (int)$value,
			'float' => (float)$value,
			default => $value,
		};
	}

	private function getUriParams(array $matches, string $path): array {
		$params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
		foreach ($params as $name => $value) {
			preg_match('#\{' . $name . '(?::(\w+))?}#', $path, $m);
			$type = $m[1] ?? 'string';
			$params[$name] = $this->convertType($value, $type);
		}
		return $params;
	}
}