<?php
header('Content-Type: application/json');
require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
include("conexion.php");
require_once('ventas_utils.php');
require_once('auditoria_utils.php');

$data = json_decode(file_get_contents('php://input'), true);
$id_venta = intval($data['id_venta'] ?? 0);
$productos_nuevos = $data['productos'] ?? [];
$metodo_pago = trim($data['metodo_pago'] ?? 'efectivo');
$eliminar_venta = !empty($data['eliminar_venta']);
$motivo_devolucion = trim($data['motivo_devolucion'] ?? '');

if (!$id_venta || !is_array($productos_nuevos)) {
    echo json_encode(["success" => false, "message" => "Datos inválidos"]);
    exit;
}

if (!$eliminar_venta && !in_array($metodo_pago, ['efectivo', 'transferencia', 'otro'], true)) {
    echo json_encode(["success" => false, "message" => "Método de pago inválido para una edición"]);
    exit;
}

try {
    mysqli_begin_transaction($conexion);

    $tieneMetodoPago = false;
    $columnaNota = null;
    $consultaColumna = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'metodo_pago'");
    if ($consultaColumna && mysqli_num_rows($consultaColumna) > 0) {
        $tieneMetodoPago = true;
    }
    $consultaNotas = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'notas'");
    if ($consultaNotas && mysqli_num_rows($consultaNotas) > 0) {
        $columnaNota = 'notas';
    } else {
        $consultaObservaciones = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'observaciones'");
        if ($consultaObservaciones && mysqli_num_rows($consultaObservaciones) > 0) {
            $columnaNota = 'observaciones';
        }
    }

    $sql_total = $tieneMetodoPago
        ? "SELECT total, metodo_pago FROM ventas WHERE id_venta = ? FOR UPDATE"
        : "SELECT total FROM ventas WHERE id_venta = ? FOR UPDATE";
    $stmt_total = mysqli_prepare($conexion, $sql_total);
    if (!$stmt_total) {
        throw new Exception('No se pudo consultar la venta.');
    }
    mysqli_stmt_bind_param($stmt_total, "i", $id_venta);
    if (!mysqli_stmt_execute($stmt_total)) {
        throw new Exception('No se pudo consultar la venta.');
    }
    $result = mysqli_stmt_get_result($stmt_total);
    $row = mysqli_fetch_assoc($result);
    if (!$row) {
        throw new Exception('Venta no encontrada.');
    }
    $total_original = $row ? (float)$row['total'] : 0;
    $metodoOriginal = trim((string)($row['metodo_pago'] ?? ''));

    // La terminal ya procesó un importe concreto. Se permite anular la venta
    // (queda marcada como "devuelto" y conserva trazabilidad), pero no cambiar
    // parcialmente sus artículos desde aquí porque eso desalinearía el cobro
    // físico de la terminal.
    $esDevolucionTotal = $eliminar_venta || empty($productos_nuevos);
    if (!$esDevolucionTotal && ($metodoOriginal === 'tarjeta' || $metodoOriginal === 'fiado')) {
        throw new Exception('Las ventas con tarjeta o fiado no se editan desde aquí para conservar el cobro y el saldo de la libreta. Registra una corrección o devolución por separado.');
    }

    $sql_detalles = "SELECT id_producto, cantidad FROM detalle_ventas WHERE id_venta = ?";
    $stmt_detalles = mysqli_prepare($conexion, $sql_detalles);
    if (!$stmt_detalles) {
        throw new Exception('No se pudieron consultar los artículos de la venta.');
    }
    mysqli_stmt_bind_param($stmt_detalles, "i", $id_venta);
    if (!mysqli_stmt_execute($stmt_detalles)) {
        throw new Exception('No se pudieron consultar los artículos de la venta.');
    }
    $result_detalles = mysqli_stmt_get_result($stmt_detalles);

    $sql_stock_restore = "UPDATE productos SET stock = stock + ? WHERE id = ?";
    $stmt_stock_restore = mysqli_prepare($conexion, $sql_stock_restore);
    if (!$stmt_stock_restore) {
        throw new Exception('No se pudo preparar la restauración de inventario.');
    }

    while ($detalle = mysqli_fetch_assoc($result_detalles)) {
        $idProductoAnterior = (int)$detalle['id_producto'];
        $cantidadAnterior = (float)$detalle['cantidad'];
        mysqli_stmt_bind_param($stmt_stock_restore, "di", $cantidadAnterior, $idProductoAnterior);
        if (!mysqli_stmt_execute($stmt_stock_restore) || mysqli_stmt_affected_rows($stmt_stock_restore) !== 1) {
            throw new Exception('No se pudo restaurar el inventario de la venta.');
        }
        nuviRestaurarLotesInventario($conexion, $idProductoAnterior, $cantidadAnterior);
    }

    $sql_delete = "DELETE FROM detalle_ventas WHERE id_venta = ?";
    $stmt_delete = mysqli_prepare($conexion, $sql_delete);
    if (!$stmt_delete) {
        throw new Exception('No se pudo preparar la actualización de artículos.');
    }
    mysqli_stmt_bind_param($stmt_delete, "i", $id_venta);
    if (!mysqli_stmt_execute($stmt_delete)) {
        throw new Exception('No se pudieron actualizar los artículos de la venta.');
    }

    if ($eliminar_venta || empty($productos_nuevos)) {
        $stmtLibreta = mysqli_prepare($conexion, "SELECT id_cliente, tipo_movimiento, monto FROM libreta_digital WHERE id_venta = ?");
        if (!$stmtLibreta) {
            throw new Exception('No se pudo consultar el movimiento de libreta.');
        }
        mysqli_stmt_bind_param($stmtLibreta, "i", $id_venta);
        if (!mysqli_stmt_execute($stmtLibreta)) {
            throw new Exception('No se pudo consultar el movimiento de libreta.');
        }
        $resultadoLibreta = mysqli_stmt_get_result($stmtLibreta);

        while ($mov = mysqli_fetch_assoc($resultadoLibreta)) {
            if (($mov['tipo_movimiento'] ?? '') === 'deuda' && !empty($mov['id_cliente'])) {
                $montoMovimiento = (float)$mov['monto'];
                $idCliente = (int)$mov['id_cliente'];
                $stmtSaldo = mysqli_prepare($conexion, "UPDATE clientes SET saldo_actual = saldo_actual - ? WHERE id_cliente = ?");
                if (!$stmtSaldo) {
                    throw new Exception('No se pudo restaurar el saldo de libreta.');
                }
                mysqli_stmt_bind_param($stmtSaldo, "di", $montoMovimiento, $idCliente);
                if (!mysqli_stmt_execute($stmtSaldo)) {
                    throw new Exception('No se pudo restaurar el saldo de libreta.');
                }
            }
        }

        $stmtDeleteLibreta = mysqli_prepare($conexion, "DELETE FROM libreta_digital WHERE id_venta = ?");
        if (!$stmtDeleteLibreta) {
            throw new Exception('No se pudo preparar la devolución de libreta.');
        }
        mysqli_stmt_bind_param($stmtDeleteLibreta, "i", $id_venta);
        if (!mysqli_stmt_execute($stmtDeleteLibreta)) {
            throw new Exception('No se pudo actualizar la libreta.');
        }

        $notaDevolucion = "Devolucion total desde editor";
        if ($motivo_devolucion !== '') {
            $notaDevolucion .= " | Motivo: " . $motivo_devolucion;
        }

        if ($tieneMetodoPago && $columnaNota) {
            $stmtDeleteVenta = mysqli_prepare($conexion, "UPDATE ventas SET total = 0, metodo_pago = 'devuelto', $columnaNota = ? WHERE id_venta = ?");
            mysqli_stmt_bind_param($stmtDeleteVenta, "si", $notaDevolucion, $id_venta);
        } elseif ($tieneMetodoPago) {
            $stmtDeleteVenta = mysqli_prepare($conexion, "UPDATE ventas SET total = 0, metodo_pago = 'devuelto' WHERE id_venta = ?");
            mysqli_stmt_bind_param($stmtDeleteVenta, "i", $id_venta);
        } elseif ($columnaNota) {
            $stmtDeleteVenta = mysqli_prepare($conexion, "UPDATE ventas SET total = 0, $columnaNota = ? WHERE id_venta = ?");
            mysqli_stmt_bind_param($stmtDeleteVenta, "si", $notaDevolucion, $id_venta);
        } else {
            $stmtDeleteVenta = mysqli_prepare($conexion, "UPDATE ventas SET total = 0 WHERE id_venta = ?");
            mysqli_stmt_bind_param($stmtDeleteVenta, "i", $id_venta);
        }
        if (!$stmtDeleteVenta || !mysqli_stmt_execute($stmtDeleteVenta)) {
            throw new Exception('No se pudo registrar la devolución.');
        }

        if (!mysqli_commit($conexion)) {
            throw new Exception('No se pudo confirmar la devolución.');
        }
        nuviRegistrarAuditoria(
            $conexion,
            'venta_devuelta',
            'venta',
            $id_venta,
            'Devolución total desde el editor por $' . number_format($total_original, 2, '.', ''),
            [
                'total_original' => $total_original,
                'metodo_original' => $metodoOriginal,
                'motivo' => $motivo_devolucion,
            ]
        );
        echo json_encode([
            "success" => true,
            "venta_eliminada" => true,
            "total_original" => $total_original,
            "total_nuevo" => 0,
            "diferencia" => -$total_original
        ]);
        exit;
    }

    // La edición pasa por el mismo validador que una venta nueva: se usan el
    // precio y el stock reales de la base de datos, con bloqueo de fila, para
    // evitar totales manipulados o existencias negativas por dos cajas a la vez.
    $ventaPreparada = nuviPrepararProductosVenta($conexion, $productos_nuevos);
    $total_nuevo = $ventaPreparada['total'];
    nuviGuardarDetallesYDescontarStock($conexion, $id_venta, $ventaPreparada['productos']);

    if ($tieneMetodoPago) {
        $sql_update_total = "UPDATE ventas SET total = ?, metodo_pago = ? WHERE id_venta = ?";
        $stmt_update_total = mysqli_prepare($conexion, $sql_update_total);
        mysqli_stmt_bind_param($stmt_update_total, "dsi", $total_nuevo, $metodo_pago, $id_venta);
    } else {
        $sql_update_total = "UPDATE ventas SET total = ? WHERE id_venta = ?";
        $stmt_update_total = mysqli_prepare($conexion, $sql_update_total);
        mysqli_stmt_bind_param($stmt_update_total, "di", $total_nuevo, $id_venta);
    }
    if (!$stmt_update_total || !mysqli_stmt_execute($stmt_update_total)) {
        throw new Exception('No se pudo actualizar el total de la venta.');
    }

    if (!mysqli_commit($conexion)) {
        throw new Exception('No se pudo confirmar la edición de la venta.');
    }

    $diferencia = $total_nuevo - $total_original;
    nuviRegistrarAuditoria(
        $conexion,
        'venta_editada',
        'venta',
        $id_venta,
        'Venta editada: $' . number_format($total_original, 2, '.', '') . ' → $' . number_format($total_nuevo, 2, '.', ''),
        [
            'total_anterior' => $total_original,
            'total_nuevo' => $total_nuevo,
            'diferencia' => $diferencia,
            'metodo_pago' => $metodo_pago,
            'articulos' => count($ventaPreparada['productos']),
        ]
    );
    echo json_encode([
        "success" => true,
        "total_original" => $total_original,
        "total_nuevo" => $total_nuevo,
        "diferencia" => $diferencia
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    error_log('editar_venta: ' . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
