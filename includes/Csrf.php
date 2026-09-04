<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminAuth.php';

final class SvAmazonReturnsCsrf
{
    public static function token(string $scope): string
    {
        SvAmazonReturnsAdminAuth::start();
        if (!isset($_SESSION['amazon_returns_csrf']) || !is_array($_SESSION['amazon_returns_csrf'])) {
            $_SESSION['amazon_returns_csrf'] = [];
        }
        $token = $_SESSION['amazon_returns_csrf'][$scope] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['amazon_returns_csrf'][$scope] = $token;
        }
        return $token;
    }

    public static function valid(string $scope, mixed $candidate): bool
    {
        SvAmazonReturnsAdminAuth::start();
        $expected = $_SESSION['amazon_returns_csrf'][$scope] ?? null;
        return is_string($expected) && is_string($candidate) && $candidate !== '' && hash_equals($expected, $candidate);
    }
}
