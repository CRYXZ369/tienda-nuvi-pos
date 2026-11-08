<?php
require_once('auth.php');
require_once('conexion.php');
require_once('auditoria_utils.php');
require_once('fecha_corte_utils.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($conexion) || !($conexion instanceof mysqli)) {
    header('Location: corte_caja.php?estado=error');
    exit;
}

function montoCorte($valor, string $campo, bool $permitirVacio = false): float
{
    $normalizado = str_replace(',', '.', trim((string) $valor));
    if ($normalizado === '' && $permitirVacio) {
        return 0.0;
    }
    if ($normalizado === '' || !is_numeric($normalizado) || (float) $normalizado < 0) {
        throw new InvalidArgumentException("El campo $campo no es válido.");
    }
    return round((float) $normalizado, 2);
}

function fechaOperativaCorte($valor, string $horaBaseDatos): string
{
    $fechaTexto = trim((string) $valor);
    if ($fechaTexto === '') {
        return nuviFechaOperativaDesdeHora($horaBaseDatos);
    }

    if (nuviFechaCorteValida($fechaTexto, substr($horaBaseDatos, 0, 10)) === null) {
        throw new InvalidArgumentException('El día del corte no es válido.');
    }
    return $fechaTexto;
}

function corteTieneFechaOperativa(mysqli $conexion): bool
{
    $resultado = mysqli_query($conexion, "SHOW COLUMNS FROM cortes_caja LIKE 'fecha_corte'");
    return $resultado instanceof mysqli_result && mysqli_num_rows($resultado) > 0;
}

$fechaCorte = '';
try {
    $idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
    $idCorte = (int) ($_POST['id_corte'] ?? 0);
    if ($idUsuario <= 0) {
        throw new Exception('Sesión inválida.');
    }
    if ($idCorte > 0 && ($_SESSION['rol'] ?? '') !== 'admin') {
        throw new Exception('Sólo administración puede editar un corte existente.');
    }

    $montoInicial = montoCorte($_POST['monto_inicial'] ?? '', 'monto inicial');
    $ventasSistema = montoCorte($_POST['total_sistema'] ?? 0, 'ventas del sistema');
    // El campo es opcional para quien no puso dinero extra; vacío equivale a $0.
    $dineroPersonal = montoCorte($_POST['dinero_personal'] ?? 0, 'inyección de dinero', true);
    $gastosTotal = montoCorte($_POST['gastos_total'] ?? 0, 'gastos');
    $dineroReal = montoCorte($_POST['dinero_real'] ?? '', 'dinero real');
    $notas = mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 1000);
    $fechaCorte = fechaOperativaCorte($_POST['fecha_corte'] ?? '', nuviHoraBaseDatos($conexion));
    $tieneFechaCorte = corteTieneFechaOperativa($conexion);

    $esperado = round(($montoInicial + $ventasSistema + $dineroPersonal) - $gastosTotal, 2);
    $diferencia = round($dineroReal - $esperado, 2);

    if ($idCorte > 0) {
        if ($tieneFechaCorte) {
            $stmt = mysqli_prepare(
                $conexion,
                'UPDATE cortes_caja SET monto_inicial = ?, ventas_totales = ?, gastos_dia = ?, dinero_personal_inyeccion = ?, monto_real_contado = ?, diferencia = ?, observaciones = ?, fecha_corte = ? WHERE id = ?'
            );
            mysqli_stmt_bind_param($stmt, 'ddddddssi', $montoInicial, $ventasSistema, $gastosTotal, $dineroPersonal, $dineroReal, $diferencia, $notas, $fechaCorte, $idCorte);
        } else {
            $stmt = mysqli_prepare(
                $conexion,
                'UPDATE cortes_caja SET monto_inicial = ?, ventas_totales = ?, gastos_dia = ?, dinero_personal_inyeccion = ?, monto_real_contado = ?, diferencia = ?, observaciones = ? WHERE id = ?'
            );
            mysqli_stmt_bind_param($stmt, 'ddddddsi', $montoInicial, $ventasSistema, $gastosTotal, $dineroPersonal, $dineroReal, $diferencia, $notas, $idCorte);
        }
        $mensaje = 'corte_actualizado';
    } else {
        if ($tieneFechaCorte) {
            $stmt = mysqli_prepare(
                $conexion,
                'INSERT INTO cortes_caja (id_usuario, monto_inicial, ventas_totales, gastos_dia, dinero_personal_inyeccion, monto_real_contado, diferencia, observaciones, fecha_cierre, fecha_corte) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            );
            mysqli_stmt_bind_param($stmt, 'iddddddss', $idUsuario, $montoInicial, $ventasSistema, $gastosTotal, $dineroPersonal, $dineroReal, $diferencia, $notas, $fechaCorte);
        } else {
            $stmt = mysqli_prepare(
                $conexion,
                'INSERT INTO cortes_caja (id_usuario, monto_inicial, ventas_totales, gastos_dia, dinero_personal_inyeccion, monto_real_contado, diferencia, observaciones, fecha_cierre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            mysqli_stmt_bind_param($stmt, 'idddddds', $idUsuario, $montoInicial, $ventasSistema, $gastosTotal, $dineroPersonal, $dineroReal, $diferencia, $notas);
        }
        $mensaje = 'ok';
    }

    if (!$stmt || !mysqli_stmt_execute($stmt)) {
        throw new Exception('No se pudo guardar el corte.');
    }
    $idCorteGuardado = $idCorte > 0 ? $idCorte : (int) mysqli_insert_id($conexion);
    nuviRegistrarAuditoria(
        $conexion,
        $idCorte > 0 ? 'corte_actualizado' : 'corte_creado',
        'corte',
        $idCorteGuardado,
        ($idCorte > 0 ? 'Corte actualizado' : 'Corte generado') . ': diferencia $' . number_format($diferencia, 2, '.', ''),
        [
            'monto_inicial' => $montoInicial,
            'ventas_sistema' => $ventasSistema,
            'gastos' => $gastosTotal,
            'dinero_real' => $dineroReal,
            'diferencia' => $diferencia,
            'fecha_corte' => $fechaCorte,
        ]
    );
    // Patrón POST/Redirect/GET: evita que al recargar se duplique el corte y
    // lleva al usuario directamente al registro que acaba de guardar.
    header(
        'Location: consultar_cortes.php?msj=' . rawurlencode($mensaje)
        . '&corte=' . $idCorteGuardado
        . '#ultimo-corte',
        true,
        303
    );
    exit;
} catch (Throwable $error) {
    error_log('guardar_corte.php: ' . $error->getMessage());
    $destinoError = 'corte_caja.php?estado=error_guardar';
    if ($fechaCorte !== '') {
        $destinoError .= '&fecha_corte=' . rawurlencode($fechaCorte);
    }
    header('Location: ' . $destinoError, true, 303);
    exit;
}
