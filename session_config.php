<?php

const DOCTRACK_SESSION_LIFETIME = 315360000;

ini_set('session.gc_maxlifetime', (string) DOCTRACK_SESSION_LIFETIME);

$isSecureConnection = (
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ||
    (($_SERVER['SERVER_PORT'] ?? '') === '443')
);

$sessionParams = [
    'lifetime' => DOCTRACK_SESSION_LIFETIME,
    'path' => '/',
    'secure' => $isSecureConnection,
    'httponly' => true,
    'samesite' => 'Lax',
];

session_set_cookie_params($sessionParams);
session_start();
setcookie(session_name(), session_id(), [
    'expires' => time() + DOCTRACK_SESSION_LIFETIME,
    'path' => $sessionParams['path'],
    'secure' => $sessionParams['secure'],
    'httponly' => $sessionParams['httponly'],
    'samesite' => $sessionParams['samesite'],
]);
