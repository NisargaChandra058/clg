<?php
// session_config.php

// 1. FORCE COOKIE SETTINGS (The Fix for Localhost/HTTP)
// These commands MUST run before session_start()
ini_set('session.cookie_secure', '0');      // Allow cookies on HTTP (Non-SSL)
ini_set('session.cookie_httponly', '1');    // Prevent JS access (Security)
ini_set('session.use_only_cookies', '1');   // Force cookies (no URL IDs)
ini_set('session.gc_maxlifetime', 3600);    // Keep session for 1 hour
?>
