<?php
header('Content-Type: application/json');
ob_start();

function nuvi_emitir_json($payload)
{
    if (headers_sent() === false) {
        header('Content-Type: application/json; charset=utf-8');
    }

    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode([
            'error' => 'No se pudo serializar el historial',
            'detalle' => json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    echo $json;
    exit;
}

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) {
        return;
    }
    nuvi_emitir_json(['error' => 'Fatal en obtener_historial: ' . ($error['message'] ?? 'desconocido')]);
});

require_once('conexion.php');
require_once('libreta_utils.php');
require_once('auth.php');

if (!isset($conexion) || !$conexion) {
    nuvi_emitir_json(['error' => 'Sin conexión a base de datos']);
}

$id_cliente = intval($_GET['id'] ?? 0);

if (!$id_cliente) {
    nuvi_emitir_json(['error' => 'Cliente inválido']);
}

try {
    $tablaLibreta = mysqli_query($conexion, "SHOW TABLES LIKE 'libreta_digital'");
    if (!$tablaLibreta || mysqli_num_rows($tablaLibreta) === 0) {
        throw new Exception('La tabla libreta_digital no existe en esta base');
    }

    $colsRes = mysqli_query($conexion, 'SHOW COLUMNS FROM libreta_digital');
    $cols = [];
    if ($colsRes) {
        while ($c = mysqli_fetch_assoc($colsRes)) {
            $cols[] = $c['Field'];
        }
    }

    $colCliente = in_array('id_cliente', $cols, true) ? 'id_cliente' : (in_array('cliente_id', $cols, true) ? 'cliente_id' : null);
    $colTipo = in_array('tipo_movimiento', $cols, true) ? 'tipo_movimiento' : (in_array('tipo', $cols, true) ? 'tipo' : null);
    $colMonto = in_array('monto', $cols, true) ? 'monto' : null;
    $colFecha = in_array('fecha_movimiento', $cols, true) ? 'fecha_movimiento' : (in_array('fecha', $cols, true) ? 'fecha' : null);
    $colNotas = in_array('notas', $cols, true) ? 'notas' : (in_array('nota', $cols, true) ? 'nota' : null);
    $colVenta = in_array('id_venta', $cols, true) ? 'id_venta' : null;
    $colUsuario = in_array('id_usuario', $cols, true) ? 'id_usuario' : (in_array('usuario_id', $cols, true) ? 'usuario_id' : null);

    if (!$colCliente || !$colTipo || !$colMonto || !$colFecha) {
        throw new Exception('La estructura de libreta_digital es incompatible');
    }

    $orderExtra = in_array('id_movimiento', $cols, true) ? ', id_movimiento DESC' : (in_array('id', $cols, true) ? ', id DESC' : '');

    $sql = "SELECT
                $colTipo AS tipo_movimiento,
                $colMonto AS monto,
                $colFecha AS fecha_movimiento,
                " . ($colNotas ? "$colNotas" : "''") . " AS notas,
                " . ($colVenta ? "$colVenta" : 'NULL') . " AS id_venta,
                " . ($colUsuario ? "$colUsuario" : 'NULL') . " AS id_usuario
            FROM libreta_digital
            WHERE $colCliente = ?
            ORDER BY $colFecha DESC$orderExtra";

    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) {
        throw new Exception(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmt, 'i', $id_cliente);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);

    $historial = [];
    $ventaIds = [];
    $usuarioIds = [];
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $fila['productos'] = [];
        $fila['nombre_usuario'] = null;
        $fila['detalle_ajuste'] = nuvi_parse_libreta_nota($fila['notas'] ?? '');
        if (!empty($fila['id_venta'])) {
            $ventaIds[] = (int) $fila['id_venta'];
        }
        if (!empty($fila['id_usuario'])) {
            $usuarioIds[] = (int) $fila['id_usuario'];
        }
        $historial[] = $fila;
    }

    $usuariosMap = [];
    if (!empty($usuarioIds)) {
        $usuarioIds = array_unique($usuarioIds);
        $usuarioIdsList = implode(',', array_map('intval', $usuarioIds));

        $usuariosSql = "SELECT id, nombre FROM usuarios WHERE id IN ($usuarioIdsList)";
        $usuariosResultado = mysqli_query($conexion, $usuariosSql);
        if ($usuariosResultado) {
            while ($filaUsuario = mysqli_fetch_assoc($usuariosResultado)) {
                $usuariosMap[$filaUsuario['id']] = $filaUsuario['nombre'];
            }
        }

        foreach ($historial as &$movimiento) {
            if (!empty($movimiento['id_usuario']) && isset($usuariosMap[$movimiento['id_usuario']])) {
                $movimiento['nombre_usuario'] = $usuariosMap[$movimiento['id_usuario']];
            }
        }
        unset($movimiento);
    }

    if ($colVenta && !empty($ventaIds)) {
        $ventaIds = array_unique($ventaIds);
        $ventaIdsList = implode(',', array_map('intval', $ventaIds));

        $productosSql = "SELECT dv.id_venta, dv.id_producto, dv.cantidad, dv.precio_unitario, dv.subtotal,
                                p.nombre AS producto_nombre, p.codigo_barras, p.marca
                         FROM detalle_ventas dv
                         LEFT JOIN productos p ON dv.id_producto = p.id
                         WHERE dv.id_venta IN ($ventaIdsList)
                         ORDER BY dv.id_venta, dv.id";

        $productosResultado = mysqli_query($conexion, $productosSql);
        if ($productosResultado) {
            $productosPorVenta = [];
            while ($filaProd = mysqli_fetch_assoc($productosResultado)) {
                $productosPorVenta[$filaProd['id_venta']][] = $filaProd;
            }

            foreach ($historial as &$movimiento) {
                if (!empty($movimiento['id_venta']) && isset($productosPorVenta[$movimiento['id_venta']])) {
                    $movimiento['productos'] = $productosPorVenta[$movimiento['id_venta']];
                }
            }
            unset($movimiento);
        }
    }

    nuvi_emitir_json($historial);
} catch (Throwable $e) {
    nuvi_emitir_json(['error' => $e->getMessage()]);
}
?>
