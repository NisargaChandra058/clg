<?php
// session_config.php - safe session defaults
// This file MUST NOT output anything, and it MUST NOT call session_start().
// The requesting script should call session_start() after including this file.

declare(strict_types=1);

// Only set ini/session params if session is NOT active.
if (session_status() === PHP_SESSION_ACTIVE) {
    // session already active; do not try to change session ini settings
    return;
}

// Secure defaults — adjust as needed for your environment
ini_set('session.use_strict_mode', '1');      // prevent uninitialized session IDs
ini_set('session.gc_maxlifetime', '1440');   // lifetime in seconds (24min default)
ini_set('session.cookie_httponly', '1');     // JavaScript cannot access cookie
// Don't forcibly set session.cookie_secure here if you run on HTTP in dev.
// Instead, rely on session_set_cookie_params below to set secure flag appropriately.

// Preferred way to set cookie parameters (works reliably)
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$cookieParams = [
    'lifetime' => 0,         // expire on browser close
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '', // or set your domain explicitly
    'secure'   => $secure,   // true only if HTTPS
    'httponly' => true,
    'samesite' => 'Lax'      // 'Lax' is a sensible default; change only if you need cross-site cookies
];

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params($cookieParams);
} else {
    // older PHP: fallback (samesite not supported directly)
    session_set_cookie_params(
        $cookieParams['lifetime'],
        $cookieParams['path'],
        $cookieParams['domain'],
        $cookieParams['secure'],
        $cookieParams['httponly']
    );
    // Note: samesite on older PHP requires manual header manipulation; avoid here.
}

// Do NOT call session_start() in this file.
// The including script should call session_start() after including this file.
