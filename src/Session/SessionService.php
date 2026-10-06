<?php

namespace Sendelius\Session;

use Ramsey\Uuid\Uuid;
use Random\RandomException;
use Sendelius\Config\Env;
use Sendelius\Http\Request;
use Sendelius\Infrastructure\Service;

class SessionService extends Service {
	private string $cookieKey = '__Secure-snd_session_token';

	public function __construct(
		private readonly Request $request
	) {
	}

	public function check(?string $token): ?SessionModel {
		if (!Env::bool('SESSION_ALLOW')) {
			return null;
		}
		if (isset($_COOKIE[$this->cookieKey])) {
			$token = trim((string)$_COOKIE[$this->cookieKey]);
		}
		if (!$token) return null;
		/** @var SessionModel|null $session */
		$session = $this->mysql('sessions')->where([
			'token' => hash('sha256', $token),
		])->get();
		if (!$session) return null;
		if ($session->expired !== 0 and $session->expired < time()) {
			$this->mysql('sessions')->where([
				'id' => $session->id
			])->delete();
			return null;
		}
		// Продлеваем только если осталось меньше 23 часов
		$sessionLifetime = Env::int('SESSION_TTL', 172800); // default = 48 часов
		if ($session->expired !== 0 and ($session->expired - time()) < ($sessionLifetime - 3600)) {
			$expired = time() + $sessionLifetime;
			$this->mysql('sessions')->where([
				'id' => $session->id
			])->update(['expired' => $expired]);
			setcookie(
				name: $this->cookieKey,
				value: $token,
				expires_or_options: [
					'expires' => $expired,
					'path' => '/',
					'domain' => '.' . $this->request->mainDomain(),
					'secure' => true,
					'httponly' => true,
					'samesite' => 'Strict',
				]
			);
			$session->expired = $expired;
		}
		return $session;
	}

	public function login(string $login, string $password, ?SessionModel $session): array {
		if (!Env::bool('SESSION_ALLOW')) {
			return [];
		}
		if ($session) return ['expired' => $session->expired];
		$user = $this->mysql('users')->where([
			'login' => $login,
			'active' => 1,
		])->get();
		if (!$user) {
			return ['error' => 'пользователь не найден', 'error_field' => 'login'];
		}
		if (!password_verify($password, $user['password'])) {
			return ['error' => 'неверный пароль', 'error_field' => 'password'];
		}
		try {
			$token = bin2hex(random_bytes(32));
		} catch (RandomException) {
			return ['error' => 'не удалось создать сессию', 'error_field' => 'login'];
		}
		$hash = hash('sha256', $token);
		$sessionLifetime = Env::int('SESSION_TTL', 172800); // default = 48 часов
		$expired = time() + $sessionLifetime;
		$this->mysql('sessions')->insert([
			'token' => $hash,
			'user_id' => $user['id'],
			'expired' => $expired,
			'request' => json_encode([
				'agent' => $this->request->userAgent(),
				'ip' => $this->request->userIp(),
				'os' => $this->request->os(),
				'browser' => $this->request->browser(),
			], JSON_UNESCAPED_UNICODE),
		]);
		setcookie(
			name: $this->cookieKey,
			value: $token,
			expires_or_options: [
				'expires' => $expired,
				'path' => '/',
				'domain' => '.' . $this->request->mainDomain(),
				'secure' => true,
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);
		return ['success' => true, 'expired' => $expired];
	}

	public function bearer(string $login, string $password): array {
		if (!Env::bool('SESSION_ALLOW')) {
			return [];
		}
		$user = $this->mysql('users')->where([
			'login' => $login,
			'active' => 1,
		])->get();
		if (!$user) {
			return ['error' => 'пользователь не найден'];
		}
		if (!password_verify($password, $user['password'])) {
			return ['error' => 'неверный пароль'];
		}
		try {
			$token = bin2hex(random_bytes(64));
		} catch (RandomException) {
			return ['error' => 'не удалось создать токен'];
		}
		$hash = hash('sha256', $token);
		$request = [
			'agent' => $this->request->userAgent(),
			'ip' => $this->request->userIp(),
			'os' => $this->request->os(),
			'browser' => $this->request->browser(),
		];
		$session = $this->mysql('sessions')->where([
			'user_id' => $user['id'],
			'expired' => 0,
		])->get();
		if (!$session) {
			$this->mysql('sessions')->insert([
				'token' => $hash,
				'user_id' => $user['id'],
				'expired' => 0,
				'request' => json_encode($request, JSON_UNESCAPED_UNICODE),
			]);
		} else {
			$this->mysql('sessions')->where([
				'id' => $session['id'],
			])->update([
				'token' => $hash,
				'request' => json_encode($request, JSON_UNESCAPED_UNICODE),
			]);
		}
		return ['success' => true, 'token' => $token];
	}

	public function logout(?SessionModel $session): array {
		if (!Env::bool('SESSION_ALLOW')) {
			return [];
		}
		if (!isset($session->id)) return ['error' => 'сессия не найдена'];
		$this->mysql('sessions')->where([
			'id' => $session->id
		])->delete();
		setcookie(
			name: $this->cookieKey,
			value: "",
			expires_or_options: [
				'expires' => -1,
				'path' => '/',
				'domain' => '.' . $this->request->mainDomain(),
				'secure' => true,
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);
		return ['success' => true];
	}

	public function currentUser(?SessionModel $session): array {
		if (!Env::bool('SESSION_ALLOW')) {
			return [];
		}
		if (!isset($session->id)) return ['error' => 'сессия не найдена'];
		$user = $this->mysql('users')->where([
			'id' => $session->userId,
			'active' => 1,
		])->get();
		if (!$user) {
			return ['error' => 'пользователь не найден'];
		}
		unset($user['password']);
		return ['user' => $user];
	}
}