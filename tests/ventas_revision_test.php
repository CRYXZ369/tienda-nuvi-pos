<?php
/** Ejecutar con NUVI_DB_* apuntando a una base migrada terminada en _test. */
if (PHP_SAPI !== 'cli' || !str_ends_with((string) getenv('NUVI_DB_NAME'), '_test')) {
    fwrite(STDERR, "Se requiere una base de pruebas cuyo nombre termine en _test.\n");
    exit(1);
}
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../ventas_utils.php';
$baseActual = mysqli_fetch_row(mysqli_query($conexion, 'SELECT DATABASE()'))[0] ?? '';
if ($baseActual !== getenv('NUVI_DB_NAME')) {
    throw new RuntimeException('La conexión no corresponde a la base de pruebas solicitada.');
}

mysqli_begin_transaction($conexion);
try {
    $codigo = 'test_' . bin2hex(random_bytes(7));
    $stmt = mysqli_prepare($conexion, "INSERT INTO productos (codigo_barras, nombre, precio_compra, precio_venta, stock, unidad_medida) VALUES (?, 'Prueba revisión', 5, 12, 3, 'pza')");
    mysqli_stmt_bind_param($stmt, 's', $codigo);
    mysqli_stmt_execute($stmt);
    $id = (int) mysqli_insert_id($conexion);
    $lineas = [['id' => $id, 'cantidad' => 1]];
    $normal = nuviPrepararProductosVenta($conexion, $lineas, true);
    $revisada = nuviPrepararProductosVenta($conexion, $lineas, true, [$id => 10]);
    if ($normal['total'] !== 12.0 || $revisada['total'] !== 10.0 || $revisada['productos'][0]['precio'] !== 10.0) {
        throw new RuntimeException('Los precios actuales y autorizados no se conservaron correctamente.');
    }
    $rechazo = false;
    try {
        nuviPrepararProductosVenta($conexion, $lineas, true, [$id => -1]);
    } catch (Exception $error) {
        if (!str_contains($error->getMessage(), 'precio autorizado')) throw $error;
        $rechazo = true;
    }
    if (!$rechazo) throw new RuntimeException('Se aceptó un precio autorizado inválido.');
    mysqli_rollback($conexion);
    echo "OK: precios actuales y revisión autorizada.\n";
} catch (Throwable $error) {
    mysqli_rollback($conexion);
    throw $error;
}
