<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Iniciar buffering para evitar salida accidental
ob_start();
require_once('auth.php');

try {
    include("conexion.php");

    if (!$conexion) {
        throw new Exception("No hay conexión a la base de datos");
    }

    $id_venta = intval($_GET['id_venta'] ?? 0);
    if (!$id_venta) {
        throw new Exception("ID de venta inválido");
    }

    $tieneMetodoPago = false;
    $consultaColumna = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'metodo_pago'");
    if ($consultaColumna && mysqli_num_rows($consultaColumna) > 0) {
        $tieneMetodoPago = true;
    }

    $campoMetodoPago = $tieneMetodoPago
        ? "v.metodo_pago"
        : "'' AS metodo_pago";

    $consultaSubtotalDetalle = mysqli_query($conexion, "SHOW COLUMNS FROM detalle_ventas LIKE 'subtotal'");
    $tieneSubtotalDetalle = $consultaSubtotalDetalle && mysqli_num_rows($consultaSubtotalDetalle) > 0;
    // Las instalaciones anteriores a la migración no tienen la columna; se
    // conserva compatibilidad mostrando el cálculo tradicional en ellas.
    $campoSubtotalDetalle = $tieneSubtotalDetalle
        ? 'dv.subtotal'
        : 'ROUND(dv.cantidad * dv.precio_unitario, 2)';

    $sqlVenta = "SELECT
                    v.id_venta,
                    v.total,
                    v.fecha_venta,
                    $campoMetodoPago
                FROM ventas v
                WHERE v.id_venta = ?";

    $stmtVenta = mysqli_prepare($conexion, $sqlVenta);
    if (!$stmtVenta) {
        throw new Exception("Error en preparación de venta: " . mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmtVenta, "i", $id_venta);
    if (!mysqli_stmt_execute($stmtVenta)) {
        throw new Exception("Error al obtener venta: " . mysqli_stmt_error($stmtVenta));
    }

    $resultadoVenta = mysqli_stmt_get_result($stmtVenta);
    $venta = mysqli_fetch_assoc($resultadoVenta);
    mysqli_stmt_close($stmtVenta);

    if (!$venta) {
        throw new Exception("Venta no encontrada");
    }

    $sql = "SELECT
                dv.id,
                dv.id_producto,
                dv.cantidad,
                dv.precio_unitario,
                $campoSubtotalDetalle AS subtotal,
                COALESCE(por_id.nombre, por_codigo.nombre, CONCAT('Producto #', dv.id_producto)) AS nombre,
                COALESCE(por_id.stock, por_codigo.stock, 0) AS stock,
                COALESCE(por_id.categoria, por_codigo.categoria, '') AS categoria,
                COALESCE(por_id.precio_venta, por_codigo.precio_venta, dv.precio_unitario) AS precio_venta,
                COALESCE(CAST(por_id.id AS CHAR), CAST(por_codigo.id AS CHAR), CAST(dv.id_producto AS CHAR)) AS producto_id_real,
                COALESCE(CAST(por_id.codigo_barras AS CHAR), CAST(por_codigo.codigo_barras AS CHAR), '') AS codigo_barras
            FROM detalle_ventas dv
            LEFT JOIN productos por_id
                ON CAST(dv.id_producto AS CHAR) = CAST(por_id.id AS CHAR)
            LEFT JOIN productos por_codigo
                ON por_id.id IS NULL
                AND CAST(dv.id_producto AS CHAR) = CAST(por_codigo.codigo_barras AS CHAR)
            WHERE dv.id_venta = ?
            ORDER BY dv.id ASC";

    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) {
        throw new Exception("Error en preparación: " . mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmt, "i", $id_venta);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error en ejecución: " . mysqli_stmt_error($stmt));
    }

    $resultado = mysqli_stmt_get_result($stmt);
    if (!$resultado) {
        throw new Exception("Error al obtener resultado: " . mysqli_error($conexion));
    }

    $detalles = [];
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $detalles[] = $fila;
    }

    mysqli_stmt_close($stmt);

    // Limpiar cualquier salida accidental
    ob_clean();
    echo json_encode([
        "venta" => $venta,
        "detalles" => $detalles
    ]);
} catch (Throwable $e) {
    // Limpiar buffer y enviar error
    ob_clean();
    echo json_encode(["error" => $e->getMessage()]);
}
exit;
