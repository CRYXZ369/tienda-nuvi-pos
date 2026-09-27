<?php
/**
 * Bitácora operativa.
 *
 * La bitácora nunca debe impedir una venta: mientras la migración no haya
 * sido ejecutada, o si su escritura falla, el flujo principal continúa y el
 * incidente queda en el log de PHP. Los detalles excluyen contraseñas y se
 * limitan para no convertir esta tabla en un respaldo accidental de datos.
 */

function nuviAuditoriaDisponible(mysqli $conexion): bool
{
    static $disponible = null;
    if ($disponible !== null) {
        return $disponible;
    }

    $res = mysqli_query($conexion, "SHOW TABLES LIKE 'bitacora_auditoria'");
    $disponible = $res instanceof mysqli_result && mysqli_num_rows($res) > 0;
    return $disponible;
}

function nuviAuditoriaDetallesSeguros(array $detalles): array
{
    $ocultos = ['password', 'password_hash', 'contrasena', 'contraseña', 'token', 'session', 'cookie'];
    $salida = [];
    foreach ($detalles as $clave => $valor) {
        $claveTexto = (string) $clave;
        if (in_array(mb_strtolower($claveTexto), $ocultos, true)) {
            $salida[$claveTexto] = '[omitido]';
            continue;
        }

        if (is_array($valor)) {
            $salida[$claveTexto] = nuviAuditoriaDetallesSeguros($valor);
        } elseif (is_scalar($valor) || $valor === null) {
            $salida[$claveTexto] = is_string($valor) ? mb_substr($valor, 0, 500) : $valor;
        }
    }
    return $salida;
}

function nuviRegistrarAuditoria(
    mysqli $conexion,
    string $accion,
    string $entidad,
    ?int $idEntidad,
    string $descripcion,
    array $detalles = []
): void {
    if (!nuviAuditoriaDisponible($conexion)) {
        return;
    }

    try {
        $idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
        $idUsuario = $idUsuario > 0 ? $idUsuario : null;
        $ip = substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45) ?: null;
        $detallesJson = json_encode(
            nuviAuditoriaDetallesSeguros($detalles),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($detallesJson === false) {
            $detallesJson = '{}';
        }

        $accion = mb_substr(trim($accion), 0, 60);
        $entidad = mb_substr(trim($entidad), 0, 60);
        $descripcion = mb_substr(trim($descripcion), 0, 255);
        $stmt = mysqli_prepare(
            $conexion,
            'INSERT INTO bitacora_auditoria (id_usuario, accion, entidad, entidad_id, descripcion, detalles_json, ip_origen) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la bitácora.');
        }
        mysqli_stmt_bind_param($stmt, 'ississs', $idUsuario, $accion, $entidad, $idEntidad, $descripcion, $detallesJson, $ip);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException(mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);
    } catch (Throwable $error) {
        error_log('nuvi bitácora: ' . $error->getMessage());
    }
}
?>
