<?php
require_once("auth.php");
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once("conexion.php");
require_once("comisiones_terminal.php");
require_once('pagos_venta_utils.php');
require_once('ventas_utils.php');
require_once('auditoria_utils.php');
header('Content-Type: application/json');

// Guardamos el código anterior completo por si hace falta restaurarlo.
/*
  ORIGEN: Código previo de finalizar_venta.php (comentado). Si se requiere,
  recuperar desde el historial del repo.
*/

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['productos']) || !is_array($data['productos'])) {
    echo json_encode(['error' => 'Carrito vacío o datos inválidos']);
    exit;
}
if (isset($data['id_usuario_origen']) && (int) $data['id_usuario_origen'] !== (int) $_SESSION['id_usuario']) {
    http_response_code(409);
    echo json_encode(['error' => 'La cuenta cambió. Inicia sesión con la cuenta que preparó la venta.']);
    exit;
}

$productos = $data['productos'];
$totalSolicitado = (float) ($data['total'] ?? 0);
$metodo_pago = trim($data['metodo_pago'] ?? 'efectivo');
$nota_venta = trim($data['nota_venta'] ?? '');
$idUsuarioVenta = max(0, (int) ($_SESSION['id_usuario'] ?? 0));
$nombreCajero = trim((string) ($_SESSION['nombre'] ?? ''));
try {
    $claveIdempotencia = nuviNormalizarClaveIdempotenciaVenta($data['idempotency_key'] ?? '');
} catch (Throwable $e) {
    echo json_encode(['error' => 'Datos de sincronización inválidos']);
    exit;
}
if ($claveIdempotencia !== '' && !nuviVentasSoportanIdempotencia($conexion)) {
    http_response_code(503);
    echo json_encode(['error' => 'Falta preparar la actualización de sincronización segura. Un administrador debe entrar a Configuración de terminales y ejecutar “Preparar terminales e interés simple”.']);
    exit;
}
$usarIdempotencia = $claveIdempotencia !== '';
if (!empty($data['venta_emergencia']) && $usarIdempotencia) {
    $ventaExistente = nuviObtenerVentaIdempotente($conexion, $claveIdempotencia);
    if ($ventaExistente) {
        echo json_encode([
            'success' => true,
            'id_venta' => (int) $ventaExistente['id_venta'],
            'total' => (float) $ventaExistente['total'],
            'sincronizacion_repetida' => true,
        ]);
        exit;
    }
}

if (!in_array($metodo_pago, ['efectivo', 'tarjeta', 'transferencia', 'otro', 'mixto'], true)) {
    echo json_encode(['error' => 'Método de pago inválido']);
    exit;
}

$metodoSecundarioMixto = trim((string) ($data['metodo_secundario'] ?? ''));
$montoSecundarioSolicitado = $data['monto_secundario'] ?? null;
$pagoEfectivoSolicitado = $data['pago_con_efectivo'] ?? $data['pago_con'] ?? null;
if ($metodo_pago === 'mixto' && !in_array($metodoSecundarioMixto, ['tarjeta', 'transferencia', 'otro'], true)) {
    echo json_encode(['error' => 'Selecciona el segundo método para el pago mixto.']);
    exit;
}

// En tarjeta, incluso cuando sólo cubre una parte de una venta mixta, el
// cliente cubre la comisión. El cálculo siempre se vuelve a hacer en el
// servidor; nunca se confía en un importe enviado por la caja.
$calculoTarjeta = null;
$terminalTarjeta = null;
$hayTarjeta = $metodo_pago === 'tarjeta'
    || ($metodo_pago === 'mixto' && $metodoSecundarioMixto === 'tarjeta');
if ($hayTarjeta) {
    if (!nuviEsquemaComisionesListo($conexion)) {
        echo json_encode([
            'error' => 'La comisión de tarjeta aún no está lista. Un administrador debe ejecutar la migración de comisiones.'
        ]);
        exit;
    }

    $idTerminalPago = intval($data['id_terminal_pago'] ?? 0);
    $terminalTarjeta = nuviObtenerTerminalPago($conexion, $idTerminalPago);
    if (!$terminalTarjeta) {
        echo json_encode(['error' => !empty($data['venta_emergencia'])
            ? 'La terminal ya no está activa. La venta ya cobrada necesita revisión.'
            : 'Selecciona una terminal activa para cobrar con tarjeta.']);
        exit;
    }
}

$tieneMetodoPago = false;
$columnaNota = null;
$consultaColumna = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'metodo_pago'");
$definicionMetodoPago = $consultaColumna ? mysqli_fetch_assoc($consultaColumna) : null;
if ($definicionMetodoPago) {
    $tieneMetodoPago = true;
}
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

// Las instalaciones antiguas pueden tener un ENUM sin 'mixto'. No se altera
// su estructura en pleno cobro: la venta queda como "otro" en esa columna,
// pero el desglose nuevo conserva y muestra correctamente el pago mixto.
$metodoPagoGuardado = $metodo_pago;
$tipoMetodoPago = strtolower((string) ($definicionMetodoPago['Type'] ?? ''));
if ($metodo_pago === 'mixto' && str_starts_with($tipoMetodoPago, 'enum(') && stripos($tipoMetodoPago, "'mixto'") === false) {
    $metodoPagoGuardado = 'otro';
}

try {
    // Se crea antes de abrir la transacción: DDL en MySQL puede confirmar una
    // transacción implícitamente. Después, venta, detalle y pagos se guardan
    // como una sola operación atómica.
    nuviAsegurarTablaPagosVenta($conexion);
} catch (Throwable $e) {
    error_log('finalizar_venta.php pagos: ' . $e->getMessage());
    echo json_encode(['error' => 'No se pudo preparar el registro del cobro. Intenta nuevamente.']);
    exit;
}
$tieneUsuarioVenta = nuviAsegurarUsuarioEnVentas($conexion);

mysqli_begin_transaction($conexion);
try {
    if ($usarIdempotencia) {
        $ventaExistente = nuviObtenerVentaIdempotente($conexion, $claveIdempotencia);
        if ($ventaExistente) {
            mysqli_commit($conexion);
            echo json_encode([
                'success' => true,
                'id_venta' => (int) $ventaExistente['id_venta'],
                'total' => (float) $ventaExistente['total'],
                'sincronizacion_repetida' => true,
            ]);
            exit;
        }
    }

    $productosSolicitados = $productos;
    if (!empty($data['venta_emergencia'])) {
        foreach ($productosSolicitados as $producto) {
            if (!empty($producto['origen_granel'])) {
                foreach ([['detalle_ventas', 'cantidad'], ['productos', 'stock']] as [$tabla, $columna]) {
                    $resultadoColumna = mysqli_query($conexion, "SHOW COLUMNS FROM `$tabla` LIKE '$columna'");
                    $definicion = $resultadoColumna ? mysqli_fetch_assoc($resultadoColumna) : null;
                    if (!$definicion || !preg_match('/^decimal\\(\\d+,\\s*([3-9]|[1-9]\\d+)\\)/i', $definicion['Type'])) {
                        throw new Exception('El inventario no admite cantidades a granel con tres decimales. La venta necesita revisión.');
                    }
                }
                break;
            }
        }
    }
    $ventaPreparada = nuviPrepararProductosVenta($conexion, $productos, true);
    $productos = $ventaPreparada['productos'];
    if (!empty($data['venta_emergencia'])) {
        $preciosActuales = [];
        foreach ($productos as $producto) {
            $preciosActuales[(int) $producto['id']] = (float) $producto['precio'];
        }
        foreach ($productosSolicitados as $producto) {
            $idProducto = (int) ($producto['id'] ?? 0);
            if (!isset($preciosActuales[$idProducto]) || !isset($producto['precio']) || !is_numeric($producto['precio'])
                || abs((float) $producto['precio'] - $preciosActuales[$idProducto]) > 0.00001) {
                throw new Exception('El precio cambió en un producto. La venta ya cobrada necesita revisión.');
            }
        }
    }
    $total = $ventaPreparada['total'];
    if ($totalSolicitado <= 0 || abs($totalSolicitado - $total) > 0.01) {
        throw new Exception('El precio cambió. Actualiza el carrito antes de cobrar.');
    }

    // Construir el cobro a partir del total real ya validado. Así el ticket y
    // la caja siempre usan la misma cifra aunque alguien altere el navegador.
    $pagosVenta = [];
    $importeTarjeta = 0.0;
    $hayPagoEfectivoSolicitado = is_numeric($pagoEfectivoSolicitado);
    // Las cajas que aún tengan una página antigua guardada sin conexión no
    // enviaban este campo. Se sincronizan como pago exacto, sin inventar un
    // cambio; las ventas nuevas siempre mandan y conservan el efectivo real.
    $pagoEfectivo = $hayPagoEfectivoSolicitado ? round((float) $pagoEfectivoSolicitado, 2) : $total;
    if ($metodo_pago === 'efectivo') {
        if ($pagoEfectivo < $total - 0.004) {
            throw new Exception('El efectivo recibido es menor al total de la venta.');
        }
        $pagosVenta[] = [
            'metodo' => 'efectivo',
            'monto' => $total,
            'monto_recibido' => $pagoEfectivo,
            'cambio' => max(0, round($pagoEfectivo - $total, 2)),
        ];
    } elseif ($metodo_pago === 'mixto') {
        $montoSecundario = is_numeric($montoSecundarioSolicitado)
            ? round((float) $montoSecundarioSolicitado, 2)
            : -1.0;
        if ($montoSecundario < 0.01 || $montoSecundario > $total - 0.01) {
            throw new Exception('En el pago mixto ambos métodos deben aportar una parte del total.');
        }
        $montoEfectivo = round($total - $montoSecundario, 2);
        if (!$hayPagoEfectivoSolicitado || $pagoEfectivo < $montoEfectivo - 0.004) {
            throw new Exception('El efectivo recibido no cubre su parte del pago mixto.');
        }
        $pagosVenta[] = [
            'metodo' => 'efectivo',
            'monto' => $montoEfectivo,
            'monto_recibido' => $pagoEfectivo,
            'cambio' => max(0, round($pagoEfectivo - $montoEfectivo, 2)),
        ];
        $pagoSecundario = ['metodo' => $metodoSecundarioMixto, 'monto' => $montoSecundario];
        if ($metodoSecundarioMixto === 'tarjeta') {
            $importeTarjeta = $montoSecundario;
        }
        $pagosVenta[] = $pagoSecundario;
    } elseif ($metodo_pago === 'tarjeta') {
        $importeTarjeta = $total;
        $pagosVenta[] = ['metodo' => 'tarjeta', 'monto' => $total];
    } else {
        $pagosVenta[] = ['metodo' => $metodo_pago, 'monto' => $total];
    }

    if ($terminalTarjeta !== null) {
        $calculoTarjeta = nuviCalcularCobroTerminal($importeTarjeta, $terminalTarjeta);
        if (!empty($data['venta_emergencia'])) {
            $terminalGuardada = $data['terminal_emergencia'] ?? null;
            if (!is_array($terminalGuardada) || (int) ($terminalGuardada['id'] ?? 0) !== (int) $terminalTarjeta['id']) {
                throw new Exception('La configuración de la terminal cambió. La venta ya cobrada necesita revisión.');
            }
            foreach (['comision_porcentaje', 'iva_porcentaje', 'cargo_fijo'] as $campo) {
                if (!isset($terminalGuardada[$campo]) || !is_numeric($terminalGuardada[$campo])
                    || abs((float) $terminalGuardada[$campo] - (float) $terminalTarjeta[$campo]) > 0.00001) {
                    throw new Exception('La configuración de la terminal cambió. La venta ya cobrada necesita revisión.');
                }
            }
            if (!isset($data['cobro_tarjeta_emergencia']) || !is_numeric($data['cobro_tarjeta_emergencia'])
                || abs((float) $data['cobro_tarjeta_emergencia'] - $calculoTarjeta['monto_cobrado_tarjeta']) > 0.001) {
                throw new Exception('El importe de la terminal no coincide. La venta ya cobrada necesita revisión.');
            }
        }
        foreach ($pagosVenta as &$pago) {
            if (($pago['metodo'] ?? '') !== 'tarjeta') {
                continue;
            }
            $pago['id_terminal_pago'] = (int) $terminalTarjeta['id'];
            $pago['terminal_nombre'] = (string) $terminalTarjeta['nombre'];
            $pago['monto_cobrado_tarjeta'] = (float) $calculoTarjeta['monto_cobrado_tarjeta'];
            $pago['recargo_cliente_tarjeta'] = (float) $calculoTarjeta['recargo_cliente_tarjeta'];
        }
        unset($pago);
    }

    // Preparar insert venta según columnas disponibles
    if ($tieneMetodoPago && $columnaNota && $tieneUsuarioVenta) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, metodo_pago, id_usuario, $columnaNota) VALUES (?, NOW(), ?, ?, ?)";
    } elseif ($tieneMetodoPago && $columnaNota) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, metodo_pago, $columnaNota) VALUES (?, NOW(), ?, ?)";
    } elseif ($tieneMetodoPago && $tieneUsuarioVenta) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, metodo_pago, id_usuario) VALUES (?, NOW(), ?, ?)";
    } elseif ($tieneMetodoPago) {
        $sqlVenta = "INSERT INTO ventas (total, fecha_venta, metodo_pago) VALUES (?, NOW(), ?)";
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
        throw new Exception('Error en preparación venta: ' . mysqli_error($conexion));
    }

    if ($tieneMetodoPago && $columnaNota && $tieneUsuarioVenta) {
        mysqli_stmt_bind_param($stmtVenta, "dsis", $total, $metodoPagoGuardado, $idUsuarioVenta, $nota_venta);
    } elseif ($tieneMetodoPago && $columnaNota) {
        mysqli_stmt_bind_param($stmtVenta, "dss", $total, $metodoPagoGuardado, $nota_venta);
    } elseif ($tieneMetodoPago && $tieneUsuarioVenta) {
        mysqli_stmt_bind_param($stmtVenta, "dsi", $total, $metodoPagoGuardado, $idUsuarioVenta);
    } elseif ($tieneMetodoPago) {
        mysqli_stmt_bind_param($stmtVenta, "ds", $total, $metodoPagoGuardado);
    } elseif ($columnaNota && $tieneUsuarioVenta) {
        mysqli_stmt_bind_param($stmtVenta, "dis", $total, $idUsuarioVenta, $nota_venta);
    } elseif ($columnaNota) {
        mysqli_stmt_bind_param($stmtVenta, "ds", $total, $nota_venta);
    } elseif ($tieneUsuarioVenta) {
        mysqli_stmt_bind_param($stmtVenta, "di", $total, $idUsuarioVenta);
    } else {
        mysqli_stmt_bind_param($stmtVenta, "d", $total);
    }

    if (!mysqli_stmt_execute($stmtVenta)) {
        throw new Exception('Error al insertar venta: ' . mysqli_stmt_error($stmtVenta));
    }

    $idVenta = mysqli_insert_id($conexion);
    if (!$idVenta) {
        throw new Exception('No se obtuvo id_venta después del insert');
    }

    if ($usarIdempotencia) {
        $stmtIdempotencia = mysqli_prepare($conexion, 'UPDATE ventas SET idempotency_key = ? WHERE id_venta = ?');
        if (!$stmtIdempotencia) {
            throw new Exception('No se pudo proteger la sincronización de la venta');
        }
        mysqli_stmt_bind_param($stmtIdempotencia, 'si', $claveIdempotencia, $idVenta);
        if (!mysqli_stmt_execute($stmtIdempotencia)) {
            throw new RuntimeException('No se pudo proteger la sincronización de la venta', mysqli_stmt_errno($stmtIdempotencia));
        }
    }

    // Guardar el desglose de tarjeta. "total" permanece como el importe de
    // productos; el cobro de terminal y el recargo del cliente quedan aparte.
    $comisionTarjeta = null;
    $montoNetoTarjeta = null;
    $montoCobradoTarjeta = null;
    $recargoClienteTarjeta = null;
    if ($calculoTarjeta !== null) {
        $comisionTarjeta = $calculoTarjeta['comision_tarjeta'];
        $montoNetoTarjeta = $calculoTarjeta['monto_neto_tarjeta'];
        $montoCobradoTarjeta = $calculoTarjeta['monto_cobrado_tarjeta'];
        $recargoClienteTarjeta = $calculoTarjeta['recargo_cliente_tarjeta'];

        $idTerminalPago = (int) $terminalTarjeta['id'];
        $nombreTerminal = (string) $terminalTarjeta['nombre'];
        $stmtComision = mysqli_prepare(
            $conexion,
            "UPDATE ventas
             SET comision_tarjeta = ?, monto_neto_tarjeta = ?, monto_cobrado_tarjeta = ?, recargo_cliente_tarjeta = ?, id_terminal_pago = ?, terminal_nombre = ?
             WHERE id_venta = ?"
        );
        if (!$stmtComision) {
            throw new Exception('Error preparar desglose de tarjeta: ' . mysqli_error($conexion));
        }
        mysqli_stmt_bind_param(
            $stmtComision,
            "ddddisi",
            $comisionTarjeta,
            $montoNetoTarjeta,
            $montoCobradoTarjeta,
            $recargoClienteTarjeta,
            $idTerminalPago,
            $nombreTerminal,
            $idVenta
        );
        if (!mysqli_stmt_execute($stmtComision)) {
            throw new Exception('Error guardar desglose de tarjeta: ' . mysqli_stmt_error($stmtComision));
        }
    }

    nuviGuardarPagosVenta($conexion, (int) $idVenta, $pagosVenta);
    nuviGuardarDetallesYDescontarStock($conexion, $idVenta, $productos, true);

    mysqli_commit($conexion);
    nuviRegistrarAuditoria(
        $conexion,
        'venta_creada',
        'venta',
        (int) $idVenta,
        'Venta ' . $metodo_pago . ' registrada por $' . number_format($total, 2, '.', ''),
        [
            'total' => $total,
            'metodo_pago' => $metodo_pago,
            'articulos' => count($productos),
            'terminal' => $terminalTarjeta['nombre'] ?? null,
            'cobrado_terminal' => $montoCobradoTarjeta,
            'pagos' => $pagosVenta,
        ]
    );
    echo json_encode([
        'success' => true,
        'id_venta' => $idVenta,
        'comision_tarjeta' => $comisionTarjeta,
        'monto_neto_tarjeta' => $montoNetoTarjeta,
        'monto_cobrado_tarjeta' => $montoCobradoTarjeta,
        'recargo_cliente_tarjeta' => $recargoClienteTarjeta,
        'id_terminal_pago' => $terminalTarjeta['id'] ?? null,
        'terminal_nombre' => $terminalTarjeta['nombre'] ?? null,
        'cajero' => $nombreCajero,
        'pagos' => $pagosVenta
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    error_log('finalizar_venta.php error: ' . $e->getMessage());
    if ($usarIdempotencia && (int) $e->getCode() === 1062) {
        try {
            $ventaExistente = nuviObtenerVentaIdempotente($conexion, $claveIdempotencia);
            if ($ventaExistente) {
                echo json_encode([
                    'success' => true,
                    'id_venta' => (int) $ventaExistente['id_venta'],
                    'total' => (float) $ventaExistente['total'],
                    'sincronizacion_repetida' => true,
                ]);
                exit;
            }
        } catch (Throwable $reintento) {
            error_log('finalizar_venta.php reintento: ' . $reintento->getMessage());
        }
    }
    $mensaje = $e->getMessage();
    $mensajesCliente = [
        'Stock insuficiente',
        'El inventario aún no admite existencias negativas',
        'El stock cambió',
        'El precio cambió',
        'El inventario no admite cantidades a granel',
        'La configuración de la terminal cambió',
        'El importe de la terminal no coincide',
        'Efectivo recibido',
        'pago mixto',
        'Un producto ya no existe',
        'está pausado',
        'El redondeo no puede',
    ];
    $visible = false;
    foreach ($mensajesCliente as $texto) {
        if (strpos($mensaje, $texto) !== false) {
            $visible = true;
            break;
        }
    }
    echo json_encode(['error' => $visible ? $mensaje : 'Error procesando la venta']);
}

?>
