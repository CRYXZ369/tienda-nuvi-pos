<?php
require_once('auth.php');
require_once('conexion.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(503);
    echo json_encode(['error' => 'Sin conexión a base de datos']);
    exit;
}

// Catálogo mínimo para el POS; incluye marca porque también forma parte de la búsqueda local.
$sql = "SELECT id, codigo_barras, nombre, marca, precio_venta, unidad_medida, stock, categoria
        FROM productos
        WHERE (activo IS NULL OR activo = 1)
        ORDER BY stock ASC, id ASC";
$res = mysqli_query($conexion, $sql);
if (!$res) {
    http_response_code(500);
    error_log('obtener_todo.php: ' . mysqli_error($conexion));
    echo json_encode(['error' => 'No se pudo cargar el catálogo']);
    exit;
}

$todos = [];
while ($fila = mysqli_fetch_assoc($res)) {
    $todos[] = $fila;
}
echo json_encode($todos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
?>
