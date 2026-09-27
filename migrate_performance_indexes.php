<?php
header('Content-Type: application/json');
require_once('auth.php');
tienePermiso(['admin']);
require_once("conexion.php");

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    echo json_encode(["success" => false, "error" => "Sin conexión a base de datos"]);
    exit;
}

$changes = [];

function indexExists(mysqli $conexion, string $table, string $indexName): bool {
    $stmt = mysqli_prepare($conexion, "SHOW INDEX FROM `$table` WHERE Key_name = ?");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, "s", $indexName);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $exists = $res && mysqli_num_rows($res) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

try {
    $indexes = [
        ["table" => "productos", "name" => "idx_productos_activo_stock_id", "sql" => "CREATE INDEX idx_productos_activo_stock_id ON productos (activo, stock, id)"],
        ["table" => "libreta_digital", "name" => "idx_libreta_cliente_fecha", "sql" => "CREATE INDEX idx_libreta_cliente_fecha ON libreta_digital (id_cliente, fecha_movimiento)"],
        ["table" => "detalle_ventas", "name" => "idx_detalle_venta_producto", "sql" => "CREATE INDEX idx_detalle_venta_producto ON detalle_ventas (id_venta, id_producto)"],
        ["table" => "ventas", "name" => "idx_ventas_fecha", "sql" => "CREATE INDEX idx_ventas_fecha ON ventas (fecha_venta)"]
    ];

    foreach ($indexes as $idx) {
        if (!indexExists($conexion, $idx["table"], $idx["name"])) {
            if (!mysqli_query($conexion, $idx["sql"])) {
                $changes[] = "✗ Error creando {$idx['name']}: " . mysqli_error($conexion);
            } else {
                $changes[] = "✓ Índice creado: {$idx['name']}";
            }
        } else {
            $changes[] = "✓ Índice ya existe: {$idx['name']}";
        }
    }

    echo json_encode([
        "success" => true,
        "message" => "Optimización de índices completada",
        "changes" => $changes
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "changes" => $changes
    ]);
}
