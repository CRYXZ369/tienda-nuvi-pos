<?php
/** Ejecutar con NUVI_DB_* apuntando a una base de pruebas migrada terminada en _test. */
if (PHP_SAPI !== 'cli' || !str_ends_with((string) getenv('NUVI_DB_NAME'), '_test')) {
    fwrite(STDERR, "Se requiere una base de pruebas cuyo nombre termine en _test.\n");
    exit(1);
}

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../ventas_utils.php';
if (!$conexion instanceof mysqli) {
    throw new RuntimeException('No se pudo conectar a la base de pruebas.');
}
$baseActual = mysqli_fetch_row(mysqli_query($conexion, 'SELECT DATABASE()'))[0] ?? '';
if ($baseActual !== getenv('NUVI_DB_NAME') || !str_ends_with($baseActual, '_test')) {
    throw new RuntimeException('La conexión no corresponde a la base de pruebas solicitada.');
}

mysqli_begin_transaction($conexion);
try {
    $codigo = 'test_' . bin2hex(random_bytes(7));
    $stmt = mysqli_prepare($conexion, "INSERT INTO productos (codigo_barras, nombre, precio_compra, precio_venta, stock, unidad_medida) VALUES (?, 'Prueba stock', 5, 10, 0, 'pza')");
    mysqli_stmt_bind_param($stmt, 's', $codigo);
    mysqli_stmt_execute($stmt);
    $productoId = mysqli_insert_id($conexion);
    mysqli_query($conexion, "INSERT INTO ventas (total) VALUES (10)");
    $ventaId = mysqli_insert_id($conexion);
    $lineas = [['id' => $productoId, 'cantidad' => 1]];

    $normal = nuviPrepararProductosVenta($conexion, $lineas, true);
    nuviGuardarDetallesYDescontarStock($conexion, $ventaId, $normal['productos'], true);
    $stock = mysqli_fetch_assoc(mysqli_query($conexion, "SELECT stock FROM productos WHERE id = $productoId"));
    if ((float) $stock['stock'] !== -1.0) {
        throw new RuntimeException('La venta normal no dejó el stock en -1.');
    }

    $rechazada = false;
    try {
        nuviPrepararProductosVenta($conexion, $lineas);
    } catch (Exception $error) {
        if (!str_contains($error->getMessage(), 'Stock insuficiente')) throw $error;
        $rechazada = true;
    }
    if (!$rechazada) {
        throw new RuntimeException('La venta fiada no rechazó el stock insuficiente.');
    }

    mysqli_rollback($conexion);
    echo "OK: venta normal con stock negativo y venta fiada con stock insuficiente.\n";
} catch (Throwable $error) {
    mysqli_rollback($conexion);
    throw $error;
}
