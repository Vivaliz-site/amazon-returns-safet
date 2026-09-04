<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';

final class SvAmazonReturnsAdminAuth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('amazon_returns_admin');
        session_set_cookie_params([
            'lifetime'=>0,
            'path'=>'/',
            'secure'=>true,
            'httponly'=>true,
            'samesite'=>'Strict',
        ]);
        session_start();
    }

    public static function verifyCredentials(string $username, string $password, ?array $credentials = null): bool
    {
        $credentials ??= [
            'username'=>(string)(getenv('AMAZON_RETURNS_ADMIN_USERNAME') ?: ''),
            'password_hash'=>(string)(getenv('AMAZON_RETURNS_ADMIN_PASSWORD_HASH') ?: ''),
        ];
        $expected = trim((string)($credentials['username'] ?? ''));
        $hash = trim((string)($credentials['password_hash'] ?? ''));
        if ($expected === '' || $hash === '' || trim($username) === '' || $password === '') return false;
        return hash_equals($expected, trim($username)) && password_verify($password, $hash);
    }

    public static function login(string $username, string $password): bool
    {
        self::start();
        if (!self::verifyCredentials($username, $password)) return false;
        session_regenerate_id(true);
        $_SESSION['amazon_returns_admin'] = ['username'=>trim($username),'authenticated_at'=>time()];
        return true;
    }

    public static function loggedIn(): bool
    {
        self::start();
        return is_array($_SESSION['amazon_returns_admin'] ?? null)
            && trim((string)($_SESSION['amazon_returns_admin']['username'] ?? '')) !== '';
    }

    public static function requireLogin(bool $json = false): void
    {
        if (self::loggedIn()) return;
        if ($json) {
            http_response_code(401); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>false,'error'=>'Authentication required.']); exit;
        }
        header('Location: /login.php', true, 302); exit;
    }

    public static function username(): string
    {
        self::start();
        return trim((string)($_SESSION['amazon_returns_admin']['username'] ?? ''));
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }
}
