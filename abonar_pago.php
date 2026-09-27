<?php
header('Content-Type: application/json; charset=utf-8');
require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once('conexion.php');
require_once('libreta_utils.php');
require_once('auditoria_utils.php');

$data = json_decode(file_get_contents('php://input'), true);
$idCliente = intval($data['id_cliente'] ?? 0);
$montoAbono = round((float) ($data['monto'] ?? 0), 2);
$nota = trim((string) ($data['nota'] ?? '')) ?: 'Abono de cuenta';
$idUsuario = intval($_SESSION['id_usuario'] ?? 0);

if ($idCliente <= 0 || $montoAbono <= 0) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

try {
    // El interés pendiente se registra antes del abono, para que el recibo
    // deje claro cuánto se debía y el nuevo plazo parta del saldo restante.
    nuvi_aplicar_interes_simple_libreta($conexion, $idCliente, $idUsuario);
    mysqli_begin_transaction($conexion);

    $stmtCliente = mysqli_prepare($conexion, 'SELECT saldo_actual FROM clientes WHERE id_cliente = ? FOR UPDATE');
    if (!$stmtCliente) {
        throw new Exception('No se pudo consultar el saldo del cliente.');
    }
    mysqli_stmt_bind_param($stmtCliente, 'i', $idCliente);
    if (!mysqli_stmt_execute($stmtCliente)) {
        throw new Exception('No se pudo consultar el saldo del cliente.');
    }
    $cliente = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCliente));
    if (!$cliente) {
        throw new Exception('Cliente no encontrado.');
    }
    $saldoActual = round((float) $cliente['saldo_actual'], 2);

    // Cualquier abono reinicia el plazo sobre el saldo que quede pendiente.
    $nuevoSaldo = round($saldoActual - $montoAbono, 2);
    $estadoInteres = nuvi_reiniciar_interes_simple_libreta($conexion, $idCliente, $nuevoSaldo);
    if (!empty($estadoInteres['configuracion_lista'])) {
        $nota = 'ABONO_INTERES_V2|inicio=' . ($estadoInteres['fecha_inicio'] ?: '')
            . '|base=' . number_format((float) $estadoInteres['saldo_base'], 2, '.', '')
            . '|nota=' . str_replace('|', ' / ', $nota);
    }

    $tipoMovimiento = nuvi_resolver_tipo_movimiento_libreta($conexion, 'abono');
    $tieneUsuario = mysqli_query($conexion, "SHOW COLUMNS FROM libreta_digital LIKE 'id_usuario'");
    if ($tieneUsuario && mysqli_num_rows($tieneUsuario) > 0) {
        $stmtMovimiento = mysqli_prepare($conexion, 'INSERT INTO libreta_digital (id_cliente, id_usuario, tipo_movimiento, monto, notas) VALUES (?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmtMovimiento, 'iisds', $idCliente, $idUsuario, $tipoMovimiento, $montoAbono, $nota);
    } else {
        $stmtMovimiento = mysqli_prepare($conexion, 'INSERT INTO libreta_digital (id_cliente, tipo_movimiento, monto, notas) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmtMovimiento, 'isds', $idCliente, $tipoMovimiento, $montoAbono, $nota);
    }
    if (!$stmtMovimiento || !mysqli_stmt_execute($stmtMovimiento)) {
        throw new Exception('No se pudo registrar el abono.');
    }

    // Un abono puede exceder la deuda. El saldo negativo representa crédito
    // del cliente y se descuenta automáticamente en su próxima venta fiada.
    $stmtSaldo = mysqli_prepare($conexion, 'UPDATE clientes SET saldo_actual = ? WHERE id_cliente = ?');
    if (!$stmtSaldo) {
        throw new Exception('No se pudo preparar la actualización del saldo.');
    }
    mysqli_stmt_bind_param($stmtSaldo, 'di', $nuevoSaldo, $idCliente);
    if (!mysqli_stmt_execute($stmtSaldo)) {
        throw new Exception('No se pudo actualizar el saldo.');
    }
    if (!mysqli_commit($conexion)) {
        throw new Exception('No se pudo confirmar el abono.');
    }
    nuviRegistrarAuditoria(
        $conexion,
        'libreta_abono',
        'cliente',
        $idCliente,
        'Abono a libreta por $' . number_format($montoAbono, 2, '.', '') . ($nuevoSaldo < 0 ? ' (saldo a favor)' : ''),
        ['monto' => $montoAbono, 'saldo_anterior' => $saldoActual, 'saldo_nuevo' => $nuevoSaldo, 'nota' => $nota]
    );
    echo json_encode([
        'success' => true,
        'monto_abonado' => $montoAbono,
        'saldo_anterior' => $saldoActual,
        'saldo_nuevo' => $nuevoSaldo,
        'saldo_a_favor' => $nuevoSaldo < 0 ? abs($nuevoSaldo) : 0,
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
