<?php
header('Content-Type: application/json; charset=utf-8');
require_once('auth.php');
tienePermiso(['admin']);
require_once('conexion.php');
require_once('comisiones_terminal.php');
require_once('auditoria_utils.php');

function nuviRespuestaTerminal(bool $success, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!nuviTablaExiste($conexion, 'terminales_pago')) {
    nuviRespuestaTerminal(false, 'Primero prepara las terminales desde la pantalla de configuración.', 409);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    nuviRespuestaTerminal(false, 'Datos inválidos.', 400);
}

$accion = trim((string) ($data['accion'] ?? 'guardar'));
$id = max(0, (int) ($data['id'] ?? 0));

try {
    if ($accion === 'desactivar') {
        if ($id <= 0) {
            throw new Exception('Terminal inválida.');
        }
        $terminal = nuviObtenerTerminalPago($conexion, $id, false);
        if (!$terminal) {
            throw new Exception('La terminal ya no existe.');
        }
        $activas = nuviObtenerTerminalesPago($conexion);
        if ($terminal['activo'] && count($activas) <= 1) {
            throw new Exception('Debe permanecer al menos una terminal activa.');
        }

        mysqli_begin_transaction($conexion);
        $nuevoEstado = $terminal['activo'] ? 0 : 1;
        $stmt = mysqli_prepare($conexion, 'UPDATE terminales_pago SET activo = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $nuevoEstado, $id);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('No se pudo actualizar la terminal.');
        }
        if ($terminal['es_predeterminada'] && !$nuevoEstado) {
            mysqli_query($conexion, 'UPDATE terminales_pago SET es_predeterminada = 0 WHERE es_predeterminada = 1');
            mysqli_query($conexion, 'UPDATE terminales_pago SET es_predeterminada = 1 WHERE activo = 1 ORDER BY id ASC LIMIT 1');
        } elseif ($nuevoEstado) {
            $hayPredeterminada = mysqli_query($conexion, 'SELECT id FROM terminales_pago WHERE activo = 1 AND es_predeterminada = 1 LIMIT 1');
            if (!$hayPredeterminada || mysqli_num_rows($hayPredeterminada) === 0) {
                $stmtPredeterminada = mysqli_prepare($conexion, 'UPDATE terminales_pago SET es_predeterminada = 1 WHERE id = ?');
                mysqli_stmt_bind_param($stmtPredeterminada, 'i', $id);
                mysqli_stmt_execute($stmtPredeterminada);
            }
        }
        mysqli_commit($conexion);
        nuviRegistrarAuditoria(
            $conexion,
            'terminal_estado_actualizado',
            'terminal',
            $id,
            'Terminal ' . ($nuevoEstado ? 'reactivada' : 'desactivada') . ': ' . (string) $terminal['nombre'],
            ['activo_anterior' => (int) $terminal['activo'], 'activo_nuevo' => $nuevoEstado]
        );
        nuviRespuestaTerminal(true, $nuevoEstado ? 'Terminal reactivada.' : 'Terminal desactivada. Se conserva su historial.');
    }

    $nombre = trim((string) ($data['nombre'] ?? ''));
    $comision = isset($data['comision_porcentaje']) ? (float) $data['comision_porcentaje'] : -1;
    $iva = isset($data['iva_porcentaje']) ? (float) $data['iva_porcentaje'] : -1;
    $fijo = isset($data['cargo_fijo']) ? (float) $data['cargo_fijo'] : -1;
    $tiempo = trim((string) ($data['tiempo_acreditacion'] ?? '')) ?: 'Al día siguiente hábil';
    $predeterminada = !empty($data['es_predeterminada']) ? 1 : 0;
    if ($nombre === '' || mb_strlen($nombre) > 80 || $comision < 0 || $iva < 0 || $fijo < 0) {
        throw new Exception('Revisa el nombre y los importes de la terminal.');
    }
    if (($comision / 100) * (1 + ($iva / 100)) >= 1) {
        throw new Exception('La comisión total debe ser menor a 100%.');
    }

    $predeterminadaActual = mysqli_query($conexion, 'SELECT id FROM terminales_pago WHERE activo = 1 AND es_predeterminada = 1 LIMIT 1');
    $sinPredeterminada = !$predeterminadaActual || mysqli_num_rows($predeterminadaActual) === 0;
    mysqli_begin_transaction($conexion);
    if ($id > 0) {
        $existe = nuviObtenerTerminalPago($conexion, $id, false);
        if (!$existe) {
            throw new Exception('La terminal no existe.');
        }
        $stmt = mysqli_prepare($conexion, 'UPDATE terminales_pago SET nombre = ?, comision_porcentaje = ?, iva_porcentaje = ?, cargo_fijo = ?, tiempo_acreditacion = ?, activo = 1 WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'sdddsi', $nombre, $comision, $iva, $fijo, $tiempo, $id);
        $mensaje = 'Terminal actualizada.';
    } else {
        $stmt = mysqli_prepare($conexion, 'INSERT INTO terminales_pago (nombre, comision_porcentaje, iva_porcentaje, cargo_fijo, tiempo_acreditacion, activo, es_predeterminada) VALUES (?, ?, ?, ?, ?, 1, 0)');
        mysqli_stmt_bind_param($stmt, 'sddds', $nombre, $comision, $iva, $fijo, $tiempo);
        $mensaje = 'Terminal agregada.';
    }
    if (!mysqli_stmt_execute($stmt)) {
        if (mysqli_stmt_errno($stmt) === 1062) {
            throw new Exception('Ya existe una terminal con ese nombre.');
        }
        throw new Exception('No se pudo guardar la terminal.');
    }
    $idGuardado = $id > 0 ? $id : (int) mysqli_insert_id($conexion);

    if ($predeterminada || $sinPredeterminada) {
        mysqli_query($conexion, 'UPDATE terminales_pago SET es_predeterminada = 0 WHERE es_predeterminada = 1');
        $stmtPredeterminada = mysqli_prepare($conexion, 'UPDATE terminales_pago SET es_predeterminada = 1 WHERE id = ?');
        mysqli_stmt_bind_param($stmtPredeterminada, 'i', $idGuardado);
        mysqli_stmt_execute($stmtPredeterminada);
    }
    mysqli_commit($conexion);
    nuviRegistrarAuditoria(
        $conexion,
        $id > 0 ? 'terminal_actualizada' : 'terminal_creada',
        'terminal',
        $idGuardado,
        $mensaje . ' ' . $nombre,
        [
            'comision_porcentaje' => $comision,
            'iva_porcentaje' => $iva,
            'cargo_fijo' => $fijo,
            'predeterminada' => (bool) ($predeterminada || $sinPredeterminada),
        ]
    );
    nuviRespuestaTerminal(true, $mensaje);
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    nuviRespuestaTerminal(false, $e->getMessage(), 422);
}
?>
