<?php
header('Content-Type: application/json');
require_once('auth.php');
require_once('conexion.php');

$id_usuario = intval($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) {
    echo json_encode(['error' => 'Usuario no autenticado']);
    exit;
}

try {
    $tabla = mysqli_query($conexion, "SHOW TABLES LIKE 'pos_scores'");
    if (!$tabla || mysqli_num_rows($tabla) === 0) {
        // Es una preferencia visual; no debe impedir que el POS venda.
        echo json_encode([]);
        exit;
    }

    $sql = "SELECT producto_id, score FROM pos_scores WHERE id_usuario = ? ORDER BY score DESC";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conexion));
    }
    mysqli_stmt_bind_param($stmt, 'i', $id_usuario);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);

    $data = [];
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $data[] = [
            'producto_id' => intval($fila['producto_id']),
            'score' => floatval($fila['score'])
        ];
    }
    echo json_encode($data);
} catch (Throwable $e) {
    // La puntuación remota sólo mejora el orden de sugerencias. Ante una
    // instalación antigua o una tabla dañada se desactiva sin afectar el POS.
    error_log('pos_scores_get.php: ' . $e->getMessage());
    echo json_encode([]);
}
