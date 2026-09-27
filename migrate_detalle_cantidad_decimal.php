<?php
header('Content-Type: application/json');
require_once('auth.php');
tienePermiso(['admin']);
require_once("conexion.php");

$changes = [];

try {
    $columna = mysqli_query($conexion, "SHOW COLUMNS FROM detalle_ventas LIKE 'cantidad'");
    if (!$columna || mysqli_num_rows($columna) === 0) {
        throw new Exception("No existe la columna detalle_ventas.cantidad");
    }

    $info = mysqli_fetch_assoc($columna);
    $tipoActual = strtolower((string)($info['Type'] ?? ''));

    if (strpos($tipoActual, 'decimal') === false) {
        $sqlAlter = "ALTER TABLE detalle_ventas MODIFY COLUMN cantidad DECIMAL(10,3) NOT NULL";
        if (!mysqli_query($conexion, $sqlAlter)) {
            throw new Exception("Error al convertir cantidad a decimal: " . mysqli_error($conexion));
        }
        $changes[] = "✓ detalle_ventas.cantidad ahora es DECIMAL(10,3)";
    } else {
        $changes[] = "✓ detalle_ventas.cantidad ya está en formato decimal";
    }

    echo json_encode([
        "success" => true,
        "message" => "Migración completada",
        "changes" => $changes
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
