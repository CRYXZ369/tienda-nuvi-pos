<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/auth.php';
$idUsuario = (int) $_SESSION['id_usuario'];
session_write_close();

try {
    require __DIR__ . '/conexion.php';
    if (!$conexion instanceof mysqli || !mysqli_query($conexion, 'SELECT 1')) {
        throw new RuntimeException('No se pudo consultar la base de datos.');
    }
    echo json_encode(['success' => true, 'id_usuario' => $idUsuario]);
} catch (mysqli_sql_exception | RuntimeException $error) {
    error_log('estado_caja.php: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'No se pudo comprobar la base de datos.']);
}
