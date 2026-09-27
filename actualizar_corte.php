<?php
/** Edición controlada de un corte histórico. */
header('Content-Type: application/json; charset=utf-8');
require_once('auth.php');
require_once('conexion.php');
require_once('auditoria_utils.php');

if (!in_array($_SESSION['rol'] ?? '', ['admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sólo administración puede modificar cortes.']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
$columna = trim((string) ($_POST['columna'] ?? ''));
$valor = trim((string) ($_POST['valor'] ?? ''));
if ($id <= 0 || !in_array($columna, ['gastos_dia', 'dinero_personal_inyeccion', 'observaciones'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Datos inválidos.']);
    exit;
}

try {
    mysqli_begin_transaction($conexion);
    $valorAnterior = null;
    if ($columna === 'observaciones') {
        $consulta = mysqli_prepare($conexion, 'SELECT observaciones FROM cortes_caja WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($consulta, 'i', $id);
        mysqli_stmt_execute($consulta);
        $corte = mysqli_fetch_assoc(mysqli_stmt_get_result($consulta));
        if (!$corte) {
            throw new Exception('Corte no encontrado.');
        }
        $valorAnterior = (string) ($corte['observaciones'] ?? '');
        $stmt = mysqli_prepare($conexion, 'UPDATE cortes_caja SET observaciones = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $valor, $id);
    } else {
        $numero = (float) str_replace(',', '.', $valor);
        if ($numero < 0) {
            throw new Exception('El importe no puede ser negativo.');
        }
        $consulta = mysqli_prepare($conexion, 'SELECT monto_inicial, ventas_totales, gastos_dia, dinero_personal_inyeccion, monto_real_contado FROM cortes_caja WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($consulta, 'i', $id);
        mysqli_stmt_execute($consulta);
        $corte = mysqli_fetch_assoc(mysqli_stmt_get_result($consulta));
        if (!$corte) {
            throw new Exception('Corte no encontrado.');
        }
        $valorAnterior = $corte[$columna] ?? null;
        $gastos = $columna === 'gastos_dia' ? $numero : (float) $corte['gastos_dia'];
        $inyeccion = $columna === 'dinero_personal_inyeccion' ? $numero : (float) $corte['dinero_personal_inyeccion'];
        $esperado = (float) $corte['monto_inicial'] + (float) $corte['ventas_totales'] + $inyeccion - $gastos;
        $diferencia = round((float) $corte['monto_real_contado'] - $esperado, 2);
        $sql = "UPDATE cortes_caja SET `{$columna}` = ?, diferencia = ? WHERE id = ?";
        $stmt = mysqli_prepare($conexion, $sql);
        mysqli_stmt_bind_param($stmt, 'ddi', $numero, $diferencia, $id);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('No se pudo guardar el corte.');
    }
    mysqli_commit($conexion);
    nuviRegistrarAuditoria(
        $conexion,
        'corte_actualizado',
        'corte',
        $id,
        'Corte actualizado: ' . $columna,
        ['campo' => $columna, 'anterior' => $valorAnterior, 'nuevo' => $columna === 'observaciones' ? $valor : $numero]
    );
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
