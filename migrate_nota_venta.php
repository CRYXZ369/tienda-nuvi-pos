<?php
// Script para agregar la columna nota_venta a ventas si no existe
// Ejecutar UNA SOLA VEZ desde el navegador o línea de comandos

require_once('auth.php');
tienePermiso(['admin']);
require_once("conexion.php");

$changes = [];

try {
    $consultaColumna = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'nota_venta'");

    if ($consultaColumna && mysqli_num_rows($consultaColumna) === 0) {
        $sqlAlter = "ALTER TABLE ventas ADD COLUMN nota_venta VARCHAR(255) DEFAULT NULL";

        if (mysqli_query($conexion, $sqlAlter)) {
            $changes[] = "✓ Columna 'nota_venta' agregada a ventas";
        } else {
            throw new Exception("Error al agregar columna: " . mysqli_error($conexion));
        }
    } else {
        $changes[] = "✓ Columna 'nota_venta' ya existe en ventas";
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

?>
