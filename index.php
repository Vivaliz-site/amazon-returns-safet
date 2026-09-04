<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/AdminAuth.php';

if (SvAmazonReturnsAdminAuth::loggedIn()) {
    header('Location: /admin/amazon-returns/', true, 302);
    exit;
}
header('Location: /login.php', true, 302);
