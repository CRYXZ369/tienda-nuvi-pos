<?php
// Script para agregar la columna id_usuario a libreta_digital si no existe
// Ejecutar UNA SOLA VEZ desde el navegador o línea de comandos

require_once('auth.php');
tienePermiso(['admin']);
require_once("conexion.php");

$changes = [];

try {
    // 1. Verificar si libreta_digital tiene la columna id_usuario
    $consultaColumna = mysqli_query($conexion, "SHOW COLUMNS FROM libreta_digital LIKE 'id_usuario'");

    if ($consultaColumna && mysqli_num_rows($consultaColumna) === 0) {
        // La columna no existe, la agregamos
        $sqlAlter = "ALTER TABLE libreta_digital ADD COLUMN id_usuario INT DEFAULT NULL AFTER id_cliente";

        if (mysqli_query($conexion, $sqlAlter)) {
            $changes[] = "✓ Columna 'id_usuario' agregada a libreta_digital";
        } else {
            throw new Exception("Error al agregar columna: " . mysqli_error($conexion));
        }
    } else {
        $changes[] = "✓ Columna 'id_usuario' ya existe en libreta_digital";
    }

    // 2. Verificar si detalle_ventas tiene la columna id_usuario
    $consultaColumna2 = mysqli_query($conexion, "SHOW COLUMNS FROM detalle_ventas LIKE 'id_usuario'");

    if ($consultaColumna2 && mysqli_num_rows($consultaColumna2) === 0) {
        // La columna no existe, la agregamos
        $sqlAlter2 = "ALTER TABLE detalle_ventas ADD COLUMN id_usuario INT DEFAULT NULL AFTER id_venta";

        if (mysqli_query($conexion, $sqlAlter2)) {
            $changes[] = "✓ Columna 'id_usuario' agregada a detalle_ventas";
        } else {
            throw new Exception("Error al agregar columna: " . mysqli_error($conexion));
        }
    } else {
        $changes[] = "✓ Columna 'id_usuario' ya existe en detalle_ventas";
    }

    // 3. Crear índice para mejorar queries
    $consultaIndice = mysqli_query($conexion, "SHOW INDEX FROM libreta_digital WHERE Column_name='id_usuario'");

    if ($consultaIndice && mysqli_num_rows($consultaIndice) === 0) {
        $sqlIndice = "CREATE INDEX idx_libreta_usuario ON libreta_digital(id_usuario)";

        if (mysqli_query($conexion, $sqlIndice)) {
            $changes[] = "✓ Índice agregado en libreta_digital.id_usuario";
        }
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
