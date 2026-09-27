<?php
/** Protección CSRF para operaciones JSON autenticadas de Nuvi. */

if (!function_exists('nuviTokenCsrf')) {
    function nuviTokenCsrf(): string
    {
        if (empty($_SESSION['nuvi_csrf_token']) || !is_string($_SESSION['nuvi_csrf_token'])) {
            $_SESSION['nuvi_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['nuvi_csrf_token'];
    }
}

if (!function_exists('nuviRequerirCsrf')) {
    function nuviRequerirCsrf(): void
    {
        $recibido = trim((string) ($_SERVER['HTTP_X_NUVI_CSRF'] ?? ''));
        if ($recibido !== '' && hash_equals(nuviTokenCsrf(), $recibido)) {
            return;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode([
            'success' => false,
            'error' => 'CSRF_INVALID',
            'message' => 'La solicitud no pudo verificarse. Recarga Nuvi e inténtalo de nuevo.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
