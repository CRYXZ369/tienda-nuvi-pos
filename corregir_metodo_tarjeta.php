<?php
/**
 * Corrige una venta capturada como efectivo, transferencia u "otro" cuando
 * el cobro real se realizó con tarjeta. No modifica artículos, existencias ni
 * el total de productos: sólo deja el método y el cobro real trazables.
 */
header('Content-Type: application/json');
require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once('conexion.php');
require_once('comisiones_terminal.php');
require_once('auditoria_utils.php');

$data = json_decode(file_get_contents('php://input'), true);
$idVenta = (int) ($data['id_venta'] ?? 0);
$idTerminal = (int) ($data['id_terminal_pago'] ?? 0);
$importeRecibido = $data['monto_cobrado_tarjeta'] ?? null;

if ($idVenta <= 0 || $idTerminal <= 0 || !is_numeric($importeRecibido)) {
    echo json_encode(['success' => false, 'message' => 'Datos de corrección inválidos.']);
    exit;
}

$montoCobrado = round((float) $importeRecibido, 2);
if ($montoCobrado <= 0) {
    echo json_encode(['success' => false, 'message' => 'El cobro real de la terminal debe ser mayor a $0.']);
    exit;
}

try {
    if (!nuviEsquemaComisionesListo($conexion)) {
        throw new Exception('La estructura de comisiones todavía no está lista. Abre Configuración de terminales y prepárala antes de corregir la venta.');
    }

    mysqli_begin_transaction($conexion);

    $stmtVenta = mysqli_prepare(
        $conexion,
        "SELECT id_venta, total, metodo_pago FROM ventas WHERE id_venta = ? FOR UPDATE"
    );
    if (!$stmtVenta) {
        throw new Exception('No se pudo preparar la corrección de la venta.');
    }
    mysqli_stmt_bind_param($stmtVenta, 'i', $idVenta);
    mysqli_stmt_execute($stmtVenta);
    $resultadoVenta = mysqli_stmt_get_result($stmtVenta);
    $venta = $resultadoVenta ? mysqli_fetch_assoc($resultadoVenta) : null;
    if (!$venta) {
        throw new Exception('La venta no existe.');
    }

    $metodoOriginal = strtolower(trim((string) ($venta['metodo_pago'] ?? '')));
    $metodosCorregibles = ['efectivo', 'transferencia', 'otro'];
    if (!in_array($metodoOriginal, $metodosCorregibles, true)) {
        throw new Exception('Sólo se pueden corregir a tarjeta ventas capturadas como efectivo, transferencia u “Otro”.');
    }

    $totalProductos = round((float) $venta['total'], 2);
    if ($totalProductos <= 0) {
        throw new Exception('La venta no tiene un total válido para corregir.');
    }
    if ($montoCobrado + 0.009 < $totalProductos) {
        throw new Exception('El cobro real no puede ser menor que el total de productos.');
    }

    $terminal = nuviObtenerTerminalPago($conexion, $idTerminal);
    if (!$terminal) {
        throw new Exception('Selecciona una terminal activa.');
    }

    // El importe ingresado es el que realmente procesó la terminal. Así se
    // conserva la realidad aun si, por una caída, no se pudo añadir el recargo.
    $tasaEfectiva = ((float) $terminal['comision_porcentaje'] / 100)
        * (1 + ((float) $terminal['iva_porcentaje'] / 100));
    $cargoFijo = max(0, (float) $terminal['cargo_fijo']);
    $comision = round(($montoCobrado * $tasaEfectiva) + $cargoFijo, 2);
    $montoNeto = round($montoCobrado - $comision, 2);
    $recargoCliente = round($montoCobrado - $totalProductos, 2);
    $montoSugerido = nuviCalcularCobroTerminal($totalProductos, $terminal)['monto_cobrado_tarjeta'];
    $comisionAbsorbida = max(0, round($totalProductos - $montoNeto, 2));
    $nombreTerminal = (string) $terminal['nombre'];

    $stmtActualizar = mysqli_prepare(
        $conexion,
        "UPDATE ventas
         SET metodo_pago = 'tarjeta',
             comision_tarjeta = ?,
             monto_neto_tarjeta = ?,
             monto_cobrado_tarjeta = ?,
             recargo_cliente_tarjeta = ?,
             id_terminal_pago = ?,
             terminal_nombre = ?
         WHERE id_venta = ?"
    );
    if (!$stmtActualizar) {
        throw new Exception('No se pudo preparar el desglose de tarjeta.');
    }
    mysqli_stmt_bind_param(
        $stmtActualizar,
        'ddddisi',
        $comision,
        $montoNeto,
        $montoCobrado,
        $recargoCliente,
        $idTerminal,
        $nombreTerminal,
        $idVenta
    );
    if (!mysqli_stmt_execute($stmtActualizar)) {
        throw new Exception('No se pudo guardar la corrección de tarjeta.');
    }

    mysqli_commit($conexion);
    nuviRegistrarAuditoria(
        $conexion,
        'venta_corregida_a_tarjeta',
        'venta',
        $idVenta,
        'Método corregido de ' . $metodoOriginal . ' a Tarjeta; cobro real $' . number_format($montoCobrado, 2, '.', ''),
        [
            'metodo_anterior' => $metodoOriginal,
            'metodo_nuevo' => 'tarjeta',
            'total_productos' => $totalProductos,
            'terminal' => $nombreTerminal,
            'monto_cobrado_tarjeta' => $montoCobrado,
            'monto_sugerido' => $montoSugerido,
            'recargo_cliente' => $recargoCliente,
            'comision_absorbida_negocio' => $comisionAbsorbida,
        ]
    );

    echo json_encode([
        'success' => true,
        'total_productos' => $totalProductos,
        'terminal_nombre' => $nombreTerminal,
        'monto_cobrado_tarjeta' => $montoCobrado,
        'monto_sugerido' => $montoSugerido,
        'recargo_cliente_tarjeta' => $recargoCliente,
        'comision_tarjeta' => $comision,
        'monto_neto_tarjeta' => $montoNeto,
        'comision_absorbida_negocio' => $comisionAbsorbida,
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    error_log('corregir_metodo_tarjeta: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
