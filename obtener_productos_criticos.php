<?php
header('Content-Type: application/json; charset=utf-8');
require_once('auth.php');
require_once('conexion.php');

if (!in_array($_SESSION['rol'] ?? '', ['admin', 'supervisor'], true)) {
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para consultar inventario crítico']);
    exit;
}

if (!$conexion) {
    echo json_encode(['success' => false, 'message' => 'Sin conexión a base de datos']);
    exit;
}

$colActivo = mysqli_query($conexion, "SHOW COLUMNS FROM productos LIKE 'activo'");
$condicionActivo = ($colActivo && mysqli_num_rows($colActivo) > 0)
    ? '(p.activo IS NULL OR p.activo = 1)'
    : '1 = 1';

// La demanda se calcula con ventas reales, no con la preferencia del buscador.
// Así se incluyen ventas registradas antes de que existiera el score del POS.
$colEstadoVenta = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'estado'");
$filtroVentas = 'v.fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
if ($colEstadoVenta && mysqli_num_rows($colEstadoVenta) > 0) {
    $filtroVentas .= " AND (v.estado IS NULL OR LOWER(v.estado) NOT IN ('devuelto', 'cancelado'))";
}

$sql = "SELECT
            criticos.*,
            CASE
                WHEN criticos.stock <= 0 THEN 'agotado'
                WHEN criticos.ventas_30_dias > 0 AND criticos.dias_cobertura <= 3 THEN 'muy_urgente'
                WHEN criticos.ventas_30_dias > 0 AND criticos.dias_cobertura <= 7 THEN 'por_agotarse'
                WHEN criticos.stock <= 3 THEN 'stock_bajo'
                ELSE 'vigilancia'
            END AS estado
        FROM (
            SELECT
                p.id,
                p.nombre,
                p.marca,
                p.categoria,
                p.unidad_medida,
                p.stock,
                p.codigo_barras,
                COALESCE(ventas.ventas_30_dias, 0) AS ventas_30_dias,
                ROUND(COALESCE(ventas.ventas_30_dias, 0) / 30, 3) AS venta_diaria,
                CASE
                    WHEN COALESCE(ventas.ventas_30_dias, 0) > 0
                    THEN ROUND(p.stock / (ventas.ventas_30_dias / 30), 1)
                    ELSE NULL
                END AS dias_cobertura
            FROM productos p
            LEFT JOIN (
                SELECT dv.id_producto, SUM(dv.cantidad) AS ventas_30_dias
                FROM detalle_ventas dv
                INNER JOIN ventas v ON v.id_venta = dv.id_venta
                WHERE $filtroVentas
                GROUP BY dv.id_producto
            ) ventas ON ventas.id_producto = p.id
            WHERE $condicionActivo
        ) criticos
        WHERE criticos.stock <= 0
           OR criticos.stock <= 3
           OR (criticos.ventas_30_dias > 0 AND criticos.dias_cobertura <= 14)
        ORDER BY
            CASE
                WHEN criticos.stock <= 0 AND criticos.ventas_30_dias > 0 THEN 1
                WHEN criticos.ventas_30_dias > 0 AND criticos.dias_cobertura <= 3 THEN 2
                WHEN criticos.stock <= 0 THEN 3
                WHEN criticos.ventas_30_dias > 0 AND criticos.dias_cobertura <= 7 THEN 4
                WHEN criticos.stock <= 3 THEN 5
                ELSE 6
            END ASC,
            criticos.venta_diaria DESC,
            criticos.dias_cobertura ASC,
            criticos.stock ASC,
            criticos.nombre ASC
        LIMIT 60";

$res = mysqli_query($conexion, $sql);
if (!$res) {
    error_log('obtener_productos_criticos.php: ' . mysqli_error($conexion));
    echo json_encode(['success' => false, 'message' => 'No se pudo consultar el inventario crítico']);
    exit;
}

$rows = [];
while ($r = mysqli_fetch_assoc($res)) {
    $rows[] = $r;
}

echo json_encode([
    'success' => true,
    'periodo_dias' => 30,
    'productos' => $rows,
]);
?>
