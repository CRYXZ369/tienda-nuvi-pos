<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
ob_start();
require_once('auth.php');
require_once('comisiones_terminal.php');

function responder_json($payload) {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('obtener_ventas_recientes json_encode falló: ' . json_last_error_msg());
        $json = json_encode(['error' => 'No se pudo serializar la respuesta']);
    }
    ob_clean();
    echo $json;
}

try {
    include("conexion.php");

    if (!$conexion) {
        throw new Exception("Error de conexión");
    }

    $tieneMetodoPago = false;
    $columnaNota = null;
    $consultaColumna = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'metodo_pago'");
    if ($consultaColumna && mysqli_num_rows($consultaColumna) > 0) {
        $tieneMetodoPago = true;
    }
    $consultaNotas = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'notas'");
    if ($consultaNotas && mysqli_num_rows($consultaNotas) > 0) {
        $columnaNota = "v.notas";
    } else {
        $consultaNotaVenta = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'nota_venta'");
        if ($consultaNotaVenta && mysqli_num_rows($consultaNotaVenta) > 0) {
            $columnaNota = "v.nota_venta";
        } else {
        $consultaObservaciones = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'observaciones'");
        if ($consultaObservaciones && mysqli_num_rows($consultaObservaciones) > 0) {
            $columnaNota = "v.observaciones";
        } else {
            $consultaNota = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'nota'");
            if ($consultaNota && mysqli_num_rows($consultaNota) > 0) {
                $columnaNota = "v.nota";
            } else {
                $consultaComentario = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'comentario'");
                if ($consultaComentario && mysqli_num_rows($consultaComentario) > 0) {
                    $columnaNota = "v.comentario";
                }
            }
        }
        }
    }

    $campoMetodoPago = $tieneMetodoPago
        ? "v.metodo_pago"
        : "'' AS metodo_pago";
    $campoNotaVenta = $columnaNota
        ? "$columnaNota AS nota_venta"
        : "'' AS nota_venta";

    $tieneComision = nuviColumnaExiste($conexion, 'ventas', 'comision_tarjeta');
    $tieneMontoNeto = nuviColumnaExiste($conexion, 'ventas', 'monto_neto_tarjeta');
    $tieneMontoCobrado = nuviColumnaExiste($conexion, 'ventas', 'monto_cobrado_tarjeta');
    $tieneRecargoCliente = nuviColumnaExiste($conexion, 'ventas', 'recargo_cliente_tarjeta');
    $tieneTerminalNombre = nuviColumnaExiste($conexion, 'ventas', 'terminal_nombre');
    $campoComision = $tieneComision && $tieneMontoNeto
        ? "v.comision_tarjeta, v.monto_neto_tarjeta"
        : "NULL AS comision_tarjeta, NULL AS monto_neto_tarjeta";
    $campoCobroTarjeta = $tieneMontoCobrado
        ? "v.monto_cobrado_tarjeta"
        : "NULL AS monto_cobrado_tarjeta";
    $campoRecargoCliente = $tieneRecargoCliente
        ? "v.recargo_cliente_tarjeta"
        : "NULL AS recargo_cliente_tarjeta";
    $campoTerminalNombre = $tieneTerminalNombre
        ? "v.terminal_nombre"
        : "'' AS terminal_nombre";

    $sql = "SELECT
                v.id_venta,
                v.total,
                v.fecha_venta,
                $campoMetodoPago,
                $campoNotaVenta,
                $campoComision,
                $campoCobroTarjeta,
                $campoRecargoCliente,
                $campoTerminalNombre,
                IFNULL(cat.categorias, 'Sin categoría') AS categorias
            FROM ventas v
                        LEFT JOIN (
                SELECT
                    dv.id_venta,
                    GROUP_CONCAT(DISTINCT p.categoria SEPARATOR ', ') AS categorias
                FROM detalle_ventas dv
                LEFT JOIN productos p ON dv.id_producto = p.id
                GROUP BY dv.id_venta
            ) cat ON cat.id_venta = v.id_venta
            WHERE v.fecha_venta >= CURDATE()
              AND v.fecha_venta < CURDATE() + INTERVAL 1 DAY
                        ORDER BY v.fecha_venta DESC, v.id_venta DESC";

    $resultado = mysqli_query($conexion, $sql);
    if (!$resultado) {
        throw new Exception(mysqli_error($conexion));
    }

    $ventas = [];
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $ventas[] = $fila;
    }

    responder_json($ventas);
} catch (Throwable $e) {
    error_log('obtener_ventas_recientes.php: ' . $e->getMessage());
    responder_json(["error" => 'No se pudieron cargar las ventas recientes']);
}
exit;
?>
