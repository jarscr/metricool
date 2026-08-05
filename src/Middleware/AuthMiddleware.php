<?php

namespace Metricool\Middleware;

use Metricool\Config\Database;
use Metricool\Exceptions\AuthorizationException;
use Metricool\Services\UserService;

class AuthMiddleware
{
    private const SESSION_USER_KEY = 'metricool_auth_user';
    private const SESSION_CSRF_KEY = 'metricool_csrf_token';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (filter_var(ini_get('session.use_cookies'), FILTER_VALIDATE_BOOL)) {
                session_set_cookie_params([
                    'httponly' => true,
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'samesite' => 'Lax',
                    'path' => '/',
                ]);
            }
            session_start();
        }
    }

    public static function isEnabled(): bool
    {
        return true;
    }

    public static function isAuthenticated(): bool
    {
        return self::currentUser() !== null;
    }

    public static function requireAuth(): void
    {
        $user = self::currentUser();
        if ($user !== null) {
            $freshUser = (new UserService(Database::getConnection()))->getActiveUserById((int) $user['id']);
            if ($freshUser !== null) {
                $_SESSION[self::SESSION_USER_KEY] = $freshUser;
                return;
            }

            self::logout();
        }

        if (self::isApiRequest()) {
            self::sendApiError(new AuthorizationException('Autenticación requerida', 401, 'UNAUTHORIZED'));
            exit;
        }

        header('Location: login.php');
        exit;
    }

    public static function attemptLogin(string $email, string $password, ?UserService $users = null): bool
    {
        $email = trim($email);
        if ($email === '' || $password === '') {
            return false;
        }

        $users ??= new UserService(Database::getConnection());
        $user = $users->authenticate($email, $password);
        if ($user === null) {
            return false;
        }

        self::startSession();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_USER_KEY] = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'brand_id' => $user['brand_id'] !== null ? (int) $user['brand_id'] : null,
            'display_name' => (string) $user['display_name'],
            'active' => (bool) $user['active'],
        ];
        unset($_SESSION[self::SESSION_CSRF_KEY]);

        return true;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * @return array{id: int, email: string, role: string, brand_id: ?int, display_name: string, active: bool}|null
     */
    public static function currentUser(): ?array
    {
        self::startSession();

        $user = $_SESSION[self::SESSION_USER_KEY] ?? null;
        if (!is_array($user)) {
            return null;
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'brand_id' => isset($user['brand_id']) && $user['brand_id'] !== null ? (int) $user['brand_id'] : null,
            'display_name' => (string) ($user['display_name'] ?? ''),
            'active' => (bool) ($user['active'] ?? false),
        ];
    }

    public static function isAdmin(): bool
    {
        return (self::currentUser()['role'] ?? null) === 'admin';
    }

    public static function authorizeBrandId(?int $requested): int
    {
        $user = self::currentUser();
        if ($user === null) {
            throw new AuthorizationException('Autenticación requerida', 401, 'UNAUTHORIZED');
        }

        if ((string) $user['role'] === 'admin') {
            if ($requested === null || $requested <= 0) {
                throw new \InvalidArgumentException('Parámetro requerido: brand_id');
            }

            return $requested;
        }

        $brandId = $user['brand_id'] !== null ? (int) $user['brand_id'] : 0;
        if ($brandId <= 0) {
            throw new AuthorizationException('Cuenta sin marca asignada', 403, 'FORBIDDEN');
        }

        if ($requested !== null && $requested > 0 && $requested !== $brandId) {
            throw new AuthorizationException('No autorizado para consultar esa marca', 403, 'FORBIDDEN');
        }

        return $brandId;
    }

    public static function csrfToken(): string
    {
        self::startSession();

        if (empty($_SESSION[self::SESSION_CSRF_KEY])) {
            $_SESSION[self::SESSION_CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_CSRF_KEY];
    }

    public static function validateCsrf(?string $token): bool
    {
        self::startSession();

        $stored = $_SESSION[self::SESSION_CSRF_KEY] ?? null;
        if (!is_string($stored) || $stored === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }

    public static function requireAdmin(): void
    {
        $user = self::currentUser();
        if ($user === null) {
            throw new AuthorizationException('Autenticación requerida', 401, 'UNAUTHORIZED');
        }

        if ((string) $user['role'] !== 'admin') {
            throw new AuthorizationException('Acceso restringido a administradores', 403, 'FORBIDDEN');
        }
    }

    private static function isApiRequest(): bool
    {
        return str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'api.php')
            || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api.php');
    }

    private static function sendApiError(AuthorizationException $exception): void
    {
        http_response_code($exception->statusCode());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
