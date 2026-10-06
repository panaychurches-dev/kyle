<?php
require_once __DIR__ . '/session_config.php';
$cookieParams = session_get_cookie_params();
session_unset();
session_destroy();
setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $cookieParams['path'],
    'domain' => $cookieParams['domain'],
    'secure' => $cookieParams['secure'],
    'httponly' => $cookieParams['httponly'],
    'samesite' => $cookieParams['samesite'] ?? 'Lax',
]);
header('Location: index.php');
exit;
