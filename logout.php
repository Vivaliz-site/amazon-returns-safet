<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/AdminAuth.php';
SvAmazonReturnsAdminAuth::logout();
header('Location: /login.php', true, 302);
