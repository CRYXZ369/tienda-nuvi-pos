<?php
// Debe mantenerse público y ligero: selector y herramientas de recuperación
// lo consultan sin sesión. Responde OK sólo si web y base de datos funcionan.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/conexion.php';
if (!isset($conexion) || !($conexion instanceof mysqli) || !@mysqli_query($conexion, 'SELECT 1')) {
    http_response_code(503);
    echo 'UNAVAILABLE';
    exit;
}

http_response_code(200);
echo 'OK';
?>
