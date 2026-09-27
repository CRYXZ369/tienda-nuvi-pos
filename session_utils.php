<?php
/** Arranque uniforme y seguro de sesión para todas las rutas del sistema. */

if (!function_exists('nuviIniciarSesion')) {
    function nuviIniciarSesion(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        $cookie = session_get_cookie_params();
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookie['path'] ?: '/',
            'domain' => $cookie['domain'] ?? '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

nuviIniciarSesion();
