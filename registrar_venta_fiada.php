<?php
header('Content-Type: application/json');
require_once("conexion.php");
require_once("auth.php");
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once("libreta_utils.php");
require_once('ventas_utils.php');
require_once('auditoria_utils.php');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['id_cliente']) || empty($data['productos'])) {
    echo json_encode(["success" => false, "message" => "Datos incompletos"]);
    exit;
}
if (isset($data['id_usuario_origen']) && (int) $data['id_usuario_origen'] !== (int) $_SESSION['id_usuario']) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'La cuenta cambió. Inicia sesión con la cuenta que preparó la venta.']);
    exit;
}

// Obtener el ID del usuario autenticado
$id_usuario = intval($_SESSION['id_usuario'] ?? 0);
$nombreCajero = trim((string) ($_SESSION['nombre'] ?? ''));

$id_cliente = (int)$data['id_cliente'];
$productos = $data['productos'];
$totalSolicitado = (float) ($data['total'] ?? 0);
$nota_venta = trim($data['nota_venta'] ?? '');
try {
    $claveIdempotencia = nuviNormalizarClaveIdempotenciaVenta($data['idempotency_key'] ?? '');
} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => "Datos de sincronización inválidos"]);
    exit;
}
if ($claveIdempotencia !== '' && !nuviVentasSoportanIdempotencia($conexion)) {
    http_response_code(503);
    echo json_encode(["success" => false, "message" => "Falta preparar la actualización de sincronización segura. Un administrador debe entrar a Configuración de terminales y ejecutar “Preparar terminales e interés simple”."]);
    exit;
}
$usarIdempotencia = $claveIdempotencia !== '';

// Si la caja perdió la respuesta de una venta ya guardada, responder antes de
// aplicar recargos o tocar de nuevo el saldo del cliente.
if ($usarIdempotencia) {
    $ventaExistente = nuviObtenerVentaIdempotente($conexion, $claveIdempotencia);
    if ($ventaExistente) {
        echo json_encode([
            "success" => true,
            "id_venta" => (int) $ventaExistente['id_venta'],
            "sincronizacion_repetida" => true,
        ]);
        exit;
    }
}

// Antes de crear una nueva deuda se registra el interés simple ya vencido.
// La utilidad usa su propia transacción, por eso se invoca antes de ésta.
nuvi_aplicar_interes_simple_libreta($conexion, $id_cliente, $id_usuario);

$tieneMetodoPago = false;
$columnaNota = null;
$consultaColumna = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'metodo_pago'");
if ($consultaColumna && mysqli_num_rows($consultaColumna) > 0) {
    $tieneMetodoPago = true;
}
$consultaUsuarioLibreta = mysqli_query($conexion, "SHOW COLUMNS FROM libreta_digital LIKE 'id_usuario'");
$tieneUsuarioLibreta = $consultaUsuarioLibreta && mysqli_num_rows($consultaUsuarioLibreta) > 0;
$consultaNotas = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'notas'");
if ($consultaNotas && mysqli_num_rows($consultaNotas) > 0) {
    $columnaNota = 'notas';
} else {
    $consultaNotaVenta = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'nota_venta'");
    if ($consultaNotaVenta && mysqli_num_rows($consultaNotaVenta) > 0) {
        $columnaNota = 'nota_venta';
    } else {
    $consultaObservaciones = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'observaciones'");
    if ($consultaObservaciones && mysqli_num_rows($consultaObservaciones) > 0) {
        $columnaNota = 'observaciones';
    } else {
        $consultaNota = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'nota'");
        if ($consultaNota && mysqli_num_rows($consultaNota) > 0) {
            $columnaNota = 'nota';
        } else {
            $consultaComentario = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'comentario'");
            if ($consultaComentario && mysqli_num_rows($consultaComentario) > 0) {
                $columnaNota = 'comentario';
            }
        }
    }
    }
}

$tieneUsuarioVenta = nuviAsegurarUsuarioEnVentas($conexion);
mysqli_begin_transaction($conexion);

try {
    if ($usarIdempotencia) {
        $ventaExistente = nuviObtenerVentaIdempotente($conexion, $claveIdempotencia);
        if ($ventaExistente) {
            mysqli_commit($conexion);
            echo json_encode([
                "success" => true,
                "id_venta" => (int) $ventaExistente['id_venta'],
                "sincronizacion_repetida" => true,
            ]);
            exit;
        }
    }

    $stmtCliente = mysqli_prepare($conexion, 'SELECT saldo_actual, limite_credito, nombre_completo FROM clientes WHERE id_cliente = ? LIMIT 1 FOR UPDATE');
    if (!$stmtCliente) {
        throw new Exception('No se pudo validar la cuenta del cliente');
    }
    mysqli_stmt_bind_param($stmtCliente, 'i', $id_cliente);
    mysqli_stmt_execute($stmtCliente);
    $cliente = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCliente));
    if (!$cliente) {
        throw new Exception('El cliente no existe');
    }

    $ventaPreparada = nuviPrepararProductosVenta($conexion, $productos);
    $productos = $ventaPreparada['productos'];
    $total_venta = $ventaPreparada['total'];
    if ($totalSolicitado <= 0 || abs($totalSolicitado - $total_venta) > 0.01) {
        throw new Exception('El precio cambió. Actualiza el carrito antes de fiar.');
    }
    $saldoActual = (float) ($cliente['saldo_actual'] ?? 0);
    $limiteCredito = (float) ($cliente['limite_credito'] ?? 0);
    $nombreCliente = trim((string) ($cliente['nombre_completo'] ?? 'Cliente'));
    $nuevoSaldo = round($saldoActual + $total_venta, 2);
    if ($limiteCredito > 0 && $nuevoSaldo > $limiteCredito + 0.000001) {
        throw new Exception('La venta excede el límite de crédito del cliente');
    }

    if ($tieneMetodoPago && $columnaNota && $tieneUsuarioVenta) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, metodo_pago, id_usuario, $columnaNota) VALUES (?, NOW(), 'fiado', ?, ?)";
    } elseif ($tieneMetodoPago && $columnaNota) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, metodo_pago, $columnaNota) VALUES (?, NOW(), 'fiado', ?)";
    } elseif ($tieneMetodoPago && $tieneUsuarioVenta) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, metodo_pago, id_usuario) VALUES (?, NOW(), 'fiado', ?)";
    } elseif ($tieneMetodoPago) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, metodo_pago) VALUES (?, NOW(), 'fiado')";
    } elseif ($columnaNota && $tieneUsuarioVenta) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, id_usuario, $columnaNota) VALUES (?, NOW(), ?, ?)";
    } elseif ($columnaNota) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, $columnaNota) VALUES (?, NOW(), ?)";
    } elseif ($tieneUsuarioVenta) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, id_usuario) VALUES (?, NOW(), ?)";
    } else {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta) VALUES (?, NOW())";
    }

    $stmtVenta = mysqli_prepare($conexion, $sqlVenta);
    if (!$stmtVenta) {
        throw new Exception('No se pudo preparar la venta fiada');
    }
    if ($tieneMetodoPago && $columnaNota && $tieneUsuarioVenta) {
        mysqli_stmt_bind_param($stmtVenta, "dis", $total_venta, $id_usuario, $nota_venta);
    } elseif ($tieneMetodoPago && $columnaNota) {
        mysqli_stmt_bind_param($stmtVenta, "ds", $total_venta, $nota_venta);
    } elseif ($tieneMetodoPago && $tieneUsuarioVenta) {
        mysqli_stmt_bind_param($stmtVenta, "di", $total_venta, $id_usuario);
    } elseif ($tieneMetodoPago) {
        // La venta sólo tiene total y método fijo ('fiado').
    } elseif ($columnaNota) {
        mysqli_stmt_bind_param($stmtVenta, "ds", $total_venta, $nota_venta);
    } elseif ($tieneUsuarioVenta) {
        mysqli_stmt_bind_param($stmtVenta, "di", $total_venta, $id_usuario);
    } else {
        mysqli_stmt_bind_param($stmtVenta, "d", $total_venta);
    }
    if (!mysqli_stmt_execute($stmtVenta)) {
        throw new Exception('No se pudo registrar la venta fiada');
    }
    $id_venta = mysqli_insert_id($conexion);

    if (!$id_venta) {
        throw new Exception("No se pudo registrar la venta");
    }

    if ($usarIdempotencia) {
        $stmtIdempotencia = mysqli_prepare($conexion, 'UPDATE ventas SET idempotency_key = ? WHERE id_venta = ?');
        if (!$stmtIdempotencia) {
            throw new Exception('No se pudo proteger la sincronización de la venta');
        }
        mysqli_stmt_bind_param($stmtIdempotencia, 'si', $claveIdempotencia, $id_venta);
        if (!mysqli_stmt_execute($stmtIdempotencia)) {
            throw new RuntimeException('No se pudo proteger la sincronización de la venta', mysqli_stmt_errno($stmtIdempotencia));
        }
    }

    nuviGuardarDetallesYDescontarStock($conexion, $id_venta, $productos);

    $nota = $nota_venta !== '' ? $nota_venta : "Venta fiada desde POS";
    $stmtSaldo = mysqli_prepare($conexion, 'UPDATE clientes SET saldo_actual = ? WHERE id_cliente = ?');
    mysqli_stmt_bind_param($stmtSaldo, 'di', $nuevoSaldo, $id_cliente);
    if (!mysqli_stmt_execute($stmtSaldo)) {
        throw new Exception('No se pudo actualizar el saldo del cliente');
    }

    $estadoInteres = nuvi_registrar_deuda_interes_simple_libreta($conexion, $id_cliente, $saldoActual, $total_venta, $nuevoSaldo);
    $resumenProximoInteres = !empty($estadoInteres['configuracion_lista'])
        ? nuvi_resumen_proximo_interes_simple_libreta(
            $nuevoSaldo,
            $estadoInteres['saldo_base'] ?? 0,
            $estadoInteres['fecha_inicio'] ?? null,
            $estadoInteres['semanas_aplicadas'] ?? 0
        )
        : ['programado' => false, 'fecha_proximo' => null, 'cargo_proximo' => 0.0, 'porcentaje_proximo' => 0.0, 'saldo_base' => 0.0, 'semanas_aplicadas' => 0];

    // El recibo de crédito debe seguir reflejando el saldo que se acordó al
    // realizar esta venta, aunque más adelante se vuelva a imprimir después
    // de abonos u otras compras. Se guarda como metadato del movimiento, sin
    // afectar el texto de la nota que ve el personal en la libreta.
    $resumenFiado = [
        'cliente' => $nombreCliente !== '' ? $nombreCliente : 'Cliente',
        'importe_fiado' => round($total_venta, 2),
        'saldo_total' => $nuevoSaldo,
        'configuracion_interes' => !empty($estadoInteres['configuracion_lista']),
        'saldo_base_interes' => (float) ($resumenProximoInteres['saldo_base'] ?? 0),
        'fecha_proximo_recargo' => $resumenProximoInteres['fecha_proximo'] ?? null,
        'cargo_proximo' => (float) ($resumenProximoInteres['cargo_proximo'] ?? 0),
        'porcentaje_proximo' => (float) ($resumenProximoInteres['porcentaje_proximo'] ?? 0),
        'semanas_interes_aplicadas' => (int) ($resumenProximoInteres['semanas_aplicadas'] ?? 0),
    ];
    if (!empty($estadoInteres['configuracion_lista'])) {
        $nota = 'DEUDA_INTERES_V2|inicio=' . ($estadoInteres['fecha_inicio'] ?: '')
            . '|base=' . number_format((float) $estadoInteres['saldo_base'], 2, '.', '')
            . '|ticket_saldo=' . number_format($nuevoSaldo, 2, '.', '')
            . '|ticket_base=' . number_format((float) ($resumenProximoInteres['saldo_base'] ?? 0), 2, '.', '')
            . '|ticket_semanas=' . (int) ($resumenProximoInteres['semanas_aplicadas'] ?? 0)
            . '|ticket_proximo=' . (string) ($resumenProximoInteres['fecha_proximo'] ?? '')
            . '|ticket_porcentaje=' . number_format((float) ($resumenProximoInteres['porcentaje_proximo'] ?? 0), 2, '.', '')
            . '|ticket_cargo=' . number_format((float) ($resumenProximoInteres['cargo_proximo'] ?? 0), 2, '.', '')
            . '|nota=' . str_replace('|', ' / ', $nota);
    } else {
        $nota .= '|ticket_saldo=' . number_format($nuevoSaldo, 2, '.', '');
    }

    $stmtLibreta = mysqli_prepare(
        $conexion,
        $tieneUsuarioLibreta
            ? "INSERT INTO libreta_digital (id_cliente, id_usuario, tipo_movimiento, monto, id_venta, notas) VALUES (?, ?, 'deuda', ?, ?, ?)"
            : "INSERT INTO libreta_digital (id_cliente, tipo_movimiento, monto, id_venta, notas) VALUES (?, 'deuda', ?, ?, ?)"
    );
    if (!$stmtLibreta) {
        throw new Exception('No se pudo preparar el movimiento de libreta');
    }
    if ($tieneUsuarioLibreta) {
        mysqli_stmt_bind_param($stmtLibreta, "iidis", $id_cliente, $id_usuario, $total_venta, $id_venta, $nota);
    } else {
        mysqli_stmt_bind_param($stmtLibreta, "idis", $id_cliente, $total_venta, $id_venta, $nota);
    }
    if (!mysqli_stmt_execute($stmtLibreta)) {
        throw new Exception('No se pudo registrar el movimiento de libreta');
    }

    if (!mysqli_commit($conexion)) {
        throw new Exception('No se pudo confirmar la venta fiada');
    }
    nuviRegistrarAuditoria(
        $conexion,
        'venta_fiada_creada',
        'venta',
        (int) $id_venta,
        'Venta fiada registrada por $' . number_format($total_venta, 2, '.', ''),
        [
            'total' => $total_venta,
            'cliente_id' => $id_cliente,
            'saldo_anterior' => $saldoActual,
            'saldo_nuevo' => $nuevoSaldo,
            'articulos' => count($productos),
        ]
    );
    echo json_encode(["success" => true, "id_venta" => $id_venta, "resumen_fiado" => $resumenFiado, "cajero" => $nombreCajero], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    error_log('registrar_venta_fiada: ' . $e->getMessage());
    if ($usarIdempotencia && (int) $e->getCode() === 1062) {
        try {
            $ventaExistente = nuviObtenerVentaIdempotente($conexion, $claveIdempotencia);
            if ($ventaExistente) {
                echo json_encode([
                    "success" => true,
                    "id_venta" => (int) $ventaExistente['id_venta'],
                    "sincronizacion_repetida" => true,
                ]);
                exit;
            }
        } catch (Throwable $reintento) {
            error_log('registrar_venta_fiada reintento: ' . $reintento->getMessage());
        }
    }
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
