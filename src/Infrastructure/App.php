<?php

namespace Sendelius\Infrastructure;

use Closure;
use Dotenv\Dotenv;
use ReflectionClass;
use Sendelius\Config\Env;
use Sendelius\Http\Response;
use Sendelius\Http\Router;
use Sendelius\Logger\Logger;
use Throwable;

class App {
	private Response $response;
	private Router $router;

	public function init(
		?Closure $protectedCallback = null,
	): void {
		date_default_timezone_set('Europe/Moscow');
		ini_set('display_errors', 0);

		$reflection = new ReflectionClass($this);

		define('DS', DIRECTORY_SEPARATOR);
		define('APP_DIR', dirname($reflection->getFileName(), 2) . DS);

		$dotenv = Dotenv::createImmutable(APP_DIR);
		$dotenv->load();

		define('DEV_MODE', Env::bool('DEV_MODE'));
		Logger::init();

		$this->response = new Response();

		set_exception_handler(function (Throwable $exception): void {
			$error = [
				'error' => $exception->getMessage(),
				'file' => 'file: ' . $exception->getFile(),
				'line' => 'line: ' . $exception->getLine(),
			];
			if (DEV_MODE) $error['trace'] = $exception->getTrace();
			Logger::write('errors', $error);
			$this->response->status(500);
			$this->response->error((DEV_MODE) ? $exception->getMessage() : 'ошибка на сервере');
		});
		register_shutdown_function([$this, 'render']);

		$this->router = new Router(
			response: $this->response,
			protectedCallback: $protectedCallback
		);
	}

	public function publicRoute(string $path, string $handler, string $action): void {
		$this->router->route($path, $handler, $action);
	}

	public function protectedRoute(string $path, string $handler, string $action): void {
		$this->router->route($path, $handler, $action, true);
	}

	public function start(): void {
		$this->router->start();
	}

	private function render(): void {
		$error = error_get_last();
		if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
			Logger::write('errors', [
				'error' => $error['message'],
				'file' => 'file: ' . $error['file'],
				'line' => 'line: ' . $error['line'],
			]);
			$this->response->status(500);
			$this->response->result([
				'error' => (DEV_MODE) ? $error['message'] : 'ошибка на сервере',
				'status' => 'error'
			]);
		} else {
			if ($error = $this->response->getError()) {
				$data = [
					'error' => $error,
					'status' => 'error'
				];
				if ($this->response->getErrorField()) $data['error_field'] = $this->response->getErrorField();
				$this->response->result($data);
				if ($this->response->getStatus() == 200) $this->response->status(500);
			} else {
				$data = $this->response->getResult();
				$data['status'] = 'success';
				$this->response->result($data);
			}
		}

		header_remove('X-Powered-By');
		header('Content-Type: application/json; charset=utf-8');
		http_response_code($this->response->getStatus());
		echo json_encode($this->response->getResult(), JSON_UNESCAPED_UNICODE);
	}
}