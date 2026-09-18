<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';

final class SvAmazonReturnsAdminAuth
{
    private const IDLE_TIMEOUT_SECONDS = 3600;
    private const ABSOLUTE_TIMEOUT_SECONDS = 43200;

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
        return strcasecmp($expected, trim($username)) === 0 && password_verify($password, $hash);
    }

    public static function login(string $username, string $password): bool
    {
        self::start();
        if (!self::verifyCredentials($username, $password)) return false;
        session_regenerate_id(true);
        $now=time();
        $canonicalUsername = trim((string)(getenv('AMAZON_RETURNS_ADMIN_USERNAME') ?: $username));
        $_SESSION['amazon_returns_admin'] = [
            'username'=>$canonicalUsername !== '' ? $canonicalUsername : trim($username),
            'authenticated_at'=>$now,
            'last_seen_at'=>$now,
        ];
        return true;
    }

    public static function loggedIn(): bool
    {
        self::start();
        $session=$_SESSION['amazon_returns_admin'] ?? null;
        if(!is_array($session))return false;
        $username=trim((string)($session['username'] ?? ''));
        $authenticatedAt=(int)($session['authenticated_at'] ?? 0);
        $lastSeenAt=(int)($session['last_seen_at'] ?? $authenticatedAt);
        $now=time();
        if(
            $username==='' || $authenticatedAt<1 || $lastSeenAt<1
            || $now-$authenticatedAt>self::ABSOLUTE_TIMEOUT_SECONDS
            || $now-$lastSeenAt>self::IDLE_TIMEOUT_SECONDS
            || $authenticatedAt>$now+60 || $lastSeenAt>$now+60
        ){
            unset($_SESSION['amazon_returns_admin']);
            return false;
        }
        $_SESSION['amazon_returns_admin']['last_seen_at']=$now;
        return true;
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
