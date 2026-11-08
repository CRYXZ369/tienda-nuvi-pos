<?php
/** Mantiene viva una sesión autenticada del POS sin consultar la base de datos. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf_utils.php';
$idUsuario = (int) $_SESSION['id_usuario'];
$token = nuviTokenCsrf();

// auth.php actualiza ultima_actividad. Cerramos pronto para no retener el
// bloqueo de sesión mientras la tablet sigue trabajando.
session_write_close();

echo json_encode([
    'success' => true,
    'server_time' => date('c'),
    'id_usuario' => $idUsuario,
    'csrf_token' => $token,
], JSON_UNESCAPED_UNICODE);
