<?php
if (PHP_SAPI !== 'cli' || !str_ends_with((string) getenv('NUVI_DB_NAME'), '_test')) {
    throw new RuntimeException('Se requiere una base de pruebas terminada en _test.');
}
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../fecha_corte_utils.php';
if (!$conexion instanceof mysqli) {
    throw new RuntimeException('No se pudo conectar a la base de pruebas.');
}
$base = mysqli_fetch_row(mysqli_query($conexion, 'SELECT DATABASE()'))[0] ?? '';
if ($base !== getenv('NUVI_DB_NAME')) {
    throw new RuntimeException('La conexión no corresponde a la base de pruebas.');
}

mysqli_query($conexion, "CREATE TEMPORARY TABLE prueba_corte (
    fecha_venta DATETIME NULL, total DECIMAL(10,2) NOT NULL,
    KEY idx_fecha (fecha_venta)
)");
mysqli_query($conexion, "INSERT INTO prueba_corte VALUES
    ('2026-03-01 05:59:59', 1),
    ('2026-03-01 06:00:00', 2),
    ('2026-03-01 23:59:59', 4),
    ('2026-03-02 05:59:59', 8),
    ('2026-03-02 06:00:00', 16),
    (NULL, 32)");

$anterior = "SELECT COALESCE(SUM(total), 0) FROM prueba_corte
    WHERE (DATE(fecha_venta) = '2026-03-01' AND HOUR(fecha_venta) >= 6)
       OR (DATE(fecha_venta) = '2026-03-02' AND HOUR(fecha_venta) < 6)";
$actual = "SELECT COALESCE(SUM(total), 0) FROM prueba_corte
    WHERE fecha_venta >= '2026-03-01 06:00:00'
      AND fecha_venta < DATE_ADD('2026-03-01 06:00:00', INTERVAL 1 DAY)";
$totalAnterior = (float) mysqli_fetch_row(mysqli_query($conexion, $anterior))[0];
$totalActual = (float) mysqli_fetch_row(mysqli_query($conexion, $actual))[0];
if ($totalAnterior !== 14.0 || $totalActual !== $totalAnterior) {
    throw new RuntimeException('El cálculo del corte cambió en los límites del día operativo.');
}
$plan = mysqli_fetch_assoc(mysqli_query($conexion, 'EXPLAIN ' . $actual));
if (!str_contains((string) ($plan['possible_keys'] ?? ''), 'idx_fecha')) {
    throw new RuntimeException('La consulta no puede utilizar el índice de fecha.');
}

mysqli_query($conexion, "SET time_zone = '+02:00'");
date_default_timezone_set('Pacific/Honolulu');
$horaBase = nuviHoraBaseDatos($conexion);
$horaSql = mysqli_fetch_row(mysqli_query($conexion, "SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i')"))[0];
if (substr($horaBase, 0, 16) !== $horaSql) {
    throw new RuntimeException('El corte no está usando el reloj de la base de datos.');
}
echo "OK: límites del corte, índice y reloj de la base de datos.\n";
