<?php
header('Content-Type: application/json');
require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once('conexion.php');

$id_usuario = intval($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) {
    echo json_encode(['success' => false, 'error' => 'Usuario no autenticado']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$productos = is_array($payload['productos'] ?? null) ? $payload['productos'] : [];

try {
    $tabla = mysqli_query($conexion, "SHOW TABLES LIKE 'pos_scores'");
    if (!$tabla || mysqli_num_rows($tabla) === 0) {
        echo json_encode(['success' => true, 'pending_migration' => true]);
        exit;
    }

    $sql = "INSERT INTO pos_scores (id_usuario, producto_id, score) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE score = score + VALUES(score)";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    foreach ($productos as $producto) {
        $producto_id = intval($producto['id'] ?? $producto['producto_id'] ?? $producto['id_producto'] ?? 0);
        if ($producto_id <= 0) {
            continue;
        }
        $score = floatval($producto['cantidad'] ?? $producto['qty'] ?? $producto['cantidad_vendida'] ?? $producto['subtotal'] ?? 1);
        if ($score <= 0) {
            $score = 1;
        }
        mysqli_stmt_bind_param($stmt, 'iid', $id_usuario, $producto_id, $score);
        mysqli_stmt_execute($stmt);
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    // El contador es opcional: registrar la venta no depende de él.
    error_log('pos_scores_inc.php: ' . $e->getMessage());
    echo json_encode(['success' => true, 'disabled' => true]);
}
