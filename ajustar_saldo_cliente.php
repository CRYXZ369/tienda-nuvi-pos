<?php
header('Content-Type: application/json');
require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once('conexion.php');
require_once('libreta_utils.php');
require_once('auditoria_utils.php');

if (!in_array($_SESSION['rol'] ?? '', ['admin', 'supervisor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sólo administración puede ajustar saldos.']);
    exit;
}

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Sin conexión a base de datos']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$idCliente = intval($payload['id_cliente'] ?? 0);
$nuevoSaldo = isset($payload['nuevo_saldo']) ? floatval($payload['nuevo_saldo']) : null;
$justificacion = trim($payload['justificacion'] ?? '');
$contexto = trim($payload['contexto'] ?? 'Ajuste manual de saldo');
$idUsuario = intval($_SESSION['id_usuario'] ?? 0);

if ($idCliente <= 0 || $nuevoSaldo === null || $justificacion === '') {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos para ajustar saldo']);
    exit;
}

try {
    nuvi_aplicar_interes_simple_libreta($conexion, $idCliente, $idUsuario);

    mysqli_begin_transaction($conexion);

    $stmtCliente = mysqli_prepare($conexion, 'SELECT saldo_actual FROM clientes WHERE id_cliente = ? LIMIT 1');
    if (!$stmtCliente) {
        throw new Exception('No se pudo consultar el cliente');
    }

    mysqli_stmt_bind_param($stmtCliente, 'i', $idCliente);
    mysqli_stmt_execute($stmtCliente);
    $resCliente = mysqli_stmt_get_result($stmtCliente);
    $cliente = mysqli_fetch_assoc($resCliente);

    if (!$cliente) {
        throw new Exception('Cliente no encontrado');
    }

    $saldoAnterior = floatval($cliente['saldo_actual'] ?? 0);
    $delta = round($nuevoSaldo - $saldoAnterior, 2);

    $stmtUpdate = mysqli_prepare($conexion, 'UPDATE clientes SET saldo_actual = ? WHERE id_cliente = ?');
    if (!$stmtUpdate) {
        throw new Exception('No se pudo actualizar el saldo del cliente');
    }
    mysqli_stmt_bind_param($stmtUpdate, 'di', $nuevoSaldo, $idCliente);
    mysqli_stmt_execute($stmtUpdate);

    $estadoInteres = null;

    if (abs($delta) > 0.00001) {
        // Un ajuste autorizado fija de nuevo la base y el plazo para que un
        // dato corregido no herede interés calculado sobre una cantidad
        // equivocada.
        $estadoInteres = nuvi_reiniciar_interes_simple_libreta($conexion, $idCliente, $nuevoSaldo);
        // En esquemas v1.2 queda identificado como ajuste; en esquemas viejos
        // la utilidad conserva compatibilidad usando un tipo existente.
        $tipoMovimiento = nuvi_resolver_tipo_movimiento_libreta($conexion, 'ajuste');
        $montoMovimiento = abs($delta);
        $notaMovimiento = 'AJUSTE_SALDO|anterior=' . number_format($saldoAnterior, 2, '.', '') . '|nuevo=' . number_format($nuevoSaldo, 2, '.', '') . '|contexto=' . str_replace('|', ' / ', $contexto) . '|justificacion=' . str_replace('|', ' / ', $justificacion);
        if (!empty($estadoInteres['configuracion_lista'])) {
            $notaMovimiento .= '|interes_inicio=' . ($estadoInteres['fecha_inicio'] ?: '') . '|interes_base=' . number_format((float) $estadoInteres['saldo_base'], 2, '.', '');
        }

        $sqlLibreta = "INSERT INTO libreta_digital (id_cliente, id_usuario, tipo_movimiento, monto, notas) VALUES (?, ?, ?, ?, ?)";
        $stmtLibreta = mysqli_prepare($conexion, $sqlLibreta);
        if (!$stmtLibreta) {
            throw new Exception('No se pudo registrar el ajuste en libreta');
        }

        mysqli_stmt_bind_param($stmtLibreta, 'iisds', $idCliente, $idUsuario, $tipoMovimiento, $montoMovimiento, $notaMovimiento);
        mysqli_stmt_execute($stmtLibreta);
    }

    mysqli_commit($conexion);
    nuviRegistrarAuditoria(
        $conexion,
        'libreta_ajustada',
        'cliente',
        $idCliente,
        'Saldo de libreta ajustado: $' . number_format($saldoAnterior, 2, '.', '') . ' → $' . number_format($nuevoSaldo, 2, '.', ''),
        [
            'saldo_anterior' => $saldoAnterior,
            'saldo_nuevo' => $nuevoSaldo,
            'diferencia' => $delta,
            'contexto' => $contexto,
            'justificacion' => $justificacion,
        ]
    );
    echo json_encode([
        'success' => true,
        'saldo_anterior' => $saldoAnterior,
        'saldo_nuevo' => $nuevoSaldo,
        'diferencia' => $delta
    ]);
} catch (Exception $e) {
    mysqli_rollback($conexion);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
