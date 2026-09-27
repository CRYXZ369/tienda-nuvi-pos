<?php
require_once('auditoria_utils.php');
if (!function_exists('nuvi_parse_libreta_nota')) {
    function nuvi_parse_libreta_nota($nota)
    {
        $nota = trim((string)$nota);
        if ($nota === '') {
            return null;
        }

        $partes = explode('|', $nota);
        $tipo = strtoupper(trim($partes[0] ?? ''));
        $resultado = [
            'tipo' => $tipo,
            'texto' => $nota,
        ];

        foreach (array_slice($partes, 1) as $parte) {
            $trozos = explode('=', $parte, 2);
            if (count($trozos) !== 2) {
                continue;
            }
            $clave = trim($trozos[0]);
            $valor = trim($trozos[1]);
            $resultado[$clave] = $valor;
        }

        return $resultado;
    }
}

if (!function_exists('nuvi_resolver_tipo_movimiento_libreta')) {
    function nuvi_resolver_tipo_movimiento_libreta($conexion, $tipoSemantico)
    {
        $tipoSemantico = strtolower(trim((string)$tipoSemantico));
        $default = $tipoSemantico !== '' ? $tipoSemantico : 'movimiento';

        // Esta función se invoca durante movimientos de libreta. La definición
        // de la columna no cambia dentro de una petición, así que evitamos
        // consultar SHOW COLUMNS repetidamente.
        static $tiposResueltos = [];
        $claveConexion = is_object($conexion) && function_exists('spl_object_id')
            ? spl_object_id($conexion)
            : 'conexion';
        $claveCache = $claveConexion . ':' . $tipoSemantico;
        if (array_key_exists($claveCache, $tiposResueltos)) {
            return $tiposResueltos[$claveCache];
        }

        $consulta = mysqli_query($conexion, "SHOW COLUMNS FROM libreta_digital LIKE 'tipo_movimiento'");
        if (!$consulta || mysqli_num_rows($consulta) === 0) {
            return $tiposResueltos[$claveCache] = $default;
        }

        $columna = mysqli_fetch_assoc($consulta);
        $tipoMysql = strtolower((string)($columna['Type'] ?? ''));

        if (preg_match('/enum\((.*)\)/', $tipoMysql, $coincidencias)) {
            preg_match_all("/'((?:\\\\'|[^'])*)'/", $coincidencias[1], $valores);
            $opciones = $valores[1] ?? [];
            foreach ($opciones as $opcion) {
                $normalizada = strtolower(str_replace(['\\\'', '"'], ['', ''], $opcion));
                $coincideRecargo = in_array($tipoSemantico, ['recargo_diario', 'recargo_semanal'], true)
                    && strpos($normalizada, 'recargo') !== false;
                if ($coincideRecargo || ($tipoSemantico !== '' && strpos($normalizada, $tipoSemantico) !== false)) {
                    return $tiposResueltos[$claveCache] = $opcion;
                }
            }
            return $tiposResueltos[$claveCache] = ($opciones[0] ?? $default);
        }

        if (preg_match('/(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|bit|bool)/', $tipoMysql)) {
            $mapa = [
                'cargo' => '1',
                'abono' => '2',
                'recargo' => '3',
                'recargo_diario' => '3',
                'recargo_semanal' => '3',
                'ajuste' => '4',
            ];
            return $tiposResueltos[$claveCache] = ($mapa[$tipoSemantico] ?? '0');
        }

        if (preg_match('/varchar\((\d+)\)/', $tipoMysql, $coincidenciasLongitud)) {
            $longitud = max(1, (int)$coincidenciasLongitud[1]);
            $valores = [
                'cargo' => 'cargo',
                'abono' => 'abono',
                'recargo' => 'recargo',
                'recargo_diario' => 'recargo',
                'recargo_semanal' => 'recargo',
                'ajuste' => 'ajuste',
            ];
            $valor = $valores[$tipoSemantico] ?? $default;
            if (strlen($valor) > $longitud) {
                $valor = substr($valor, 0, $longitud);
            }
            return $tiposResueltos[$claveCache] = $valor;
        }

        return $tiposResueltos[$claveCache] = $default;
    }
}

/*
 * Interés de libreta v2: 5 % tras la primera semana completa sin abono y
 * 10 % simple adicional por cada semana posterior. La migración inicializa
 * la base el día en que se activa; nunca reescribe recargos históricos.
 */
if (!defined('NUVI_INTERES_SIMPLE_REGLA')) {
    define('NUVI_INTERES_SIMPLE_REGLA', 'INTERES_SIMPLE_V2');
}

if (!function_exists('nuvi_columnas_interes_simple_clientes')) {
    function nuvi_columnas_interes_simple_clientes($conexion)
    {
        static $cache = [];
        $clave = is_object($conexion) && function_exists('spl_object_id') ? spl_object_id($conexion) : 'conexion';
        if (isset($cache[$clave])) {
            return $cache[$clave];
        }

        $campos = [];
        $resultado = mysqli_query($conexion, 'SHOW COLUMNS FROM clientes');
        if ($resultado) {
            while ($fila = mysqli_fetch_assoc($resultado)) {
                $campos[] = (string) $fila['Field'];
            }
        }
        return $cache[$clave] = $campos;
    }
}

if (!function_exists('nuvi_interes_simple_disponible')) {
    function nuvi_interes_simple_disponible($conexion)
    {
        $campos = nuvi_columnas_interes_simple_clientes($conexion);
        return in_array('fecha_inicio_interes', $campos, true)
            && in_array('saldo_base_interes', $campos, true)
            && in_array('semanas_interes_aplicadas', $campos, true);
    }
}

if (!function_exists('nuvi_porcentaje_interes_simple')) {
    function nuvi_porcentaje_interes_simple($semanas)
    {
        $semanas = max(0, (int) $semanas);
        return $semanas <= 0 ? 0.0 : 5.0 + max(0, $semanas - 1) * 10.0;
    }
}

if (!function_exists('nuvi_calcular_interes_simple_libreta')) {
    function nuvi_calcular_interes_simple_libreta($saldoActual, $saldoBase, $fechaInicio, $semanasAplicadas, $fechaHoy = null)
    {
        $saldoActual = round((float) $saldoActual, 2);
        $saldoBase = round((float) $saldoBase, 2);
        $semanasAplicadas = max(0, (int) $semanasAplicadas);
        $hoy = $fechaHoy instanceof DateTimeInterface
            ? new DateTimeImmutable($fechaHoy->format('Y-m-d'))
            : new DateTimeImmutable('today');

        if ($saldoActual <= 0 || $saldoBase <= 0 || !$fechaInicio) {
            return ['aplicable' => false, 'dias' => 0, 'semanas' => 0, 'semanas_aplicadas' => $semanasAplicadas, 'cargo' => 0.0, 'porcentaje_pendiente' => 0.0];
        }

        try {
            $inicio = new DateTimeImmutable((new DateTimeImmutable((string) $fechaInicio))->format('Y-m-d'));
        } catch (Throwable $e) {
            return ['aplicable' => false, 'dias' => 0, 'semanas' => 0, 'semanas_aplicadas' => $semanasAplicadas, 'cargo' => 0.0, 'porcentaje_pendiente' => 0.0];
        }
        if ($inicio > $hoy) {
            return ['aplicable' => false, 'dias' => 0, 'semanas' => 0, 'semanas_aplicadas' => $semanasAplicadas, 'cargo' => 0.0, 'porcentaje_pendiente' => 0.0];
        }

        $dias = (int) $inicio->diff($hoy)->days;
        $semanas = intdiv($dias, 7);
        $porcentajeTotal = nuvi_porcentaje_interes_simple($semanas);
        $porcentajeAplicado = nuvi_porcentaje_interes_simple(min($semanas, $semanasAplicadas));
        $porcentajePendiente = max(0, $porcentajeTotal - $porcentajeAplicado);
        $cargo = round($saldoBase * $porcentajePendiente / 100, 2);

        return [
            'aplicable' => $semanas > $semanasAplicadas && $cargo > 0,
            'dias' => $dias,
            'semanas' => $semanas,
            'semanas_aplicadas' => $semanasAplicadas,
            'saldo_base' => $saldoBase,
            'fecha_inicio' => $inicio->format('Y-m-d'),
            'porcentaje_total' => $porcentajeTotal,
            'porcentaje_pendiente' => $porcentajePendiente,
            'cargo' => $cargo,
        ];
    }
}

if (!function_exists('nuvi_resumen_proximo_interes_simple_libreta')) {
    /**
     * Resume el siguiente cargo sin hacer escrituras. Sirve para explicar la
     * regla en el comprobante de una venta fiada y conserva exactamente la
     * misma escala que se aplica en nuvi_calcular_interes_simple_libreta:
     * 5 % en la primera semana y 10 % adicional por cada semana posterior.
     */
    function nuvi_resumen_proximo_interes_simple_libreta($saldoActual, $saldoBase, $fechaInicio, $semanasAplicadas): array
    {
        $saldoActual = round((float) $saldoActual, 2);
        $saldoBase = round((float) $saldoBase, 2);
        $semanasAplicadas = max(0, (int) $semanasAplicadas);

        $resultado = [
            'programado' => false,
            'fecha_proximo' => null,
            'cargo_proximo' => 0.0,
            'porcentaje_proximo' => 0.0,
            'saldo_base' => $saldoBase,
            'semanas_aplicadas' => $semanasAplicadas,
        ];

        if ($saldoActual <= 0 || $saldoBase <= 0 || !$fechaInicio) {
            return $resultado;
        }

        try {
            $inicio = new DateTimeImmutable((new DateTimeImmutable((string) $fechaInicio))->format('Y-m-d'));
        } catch (Throwable $e) {
            return $resultado;
        }

        $semanaSiguiente = $semanasAplicadas + 1;
        $porcentajeActual = nuvi_porcentaje_interes_simple($semanasAplicadas);
        $porcentajeSiguiente = nuvi_porcentaje_interes_simple($semanaSiguiente);
        $porcentajeCargo = max(0, $porcentajeSiguiente - $porcentajeActual);
        $fechaProximo = $inicio->modify('+' . ($semanaSiguiente * 7) . ' days');

        return [
            'programado' => $porcentajeCargo > 0,
            'fecha_proximo' => $fechaProximo->format('Y-m-d'),
            'cargo_proximo' => round($saldoBase * $porcentajeCargo / 100, 2),
            'porcentaje_proximo' => $porcentajeCargo,
            'saldo_base' => $saldoBase,
            'semanas_aplicadas' => $semanasAplicadas,
        ];
    }
}

if (!function_exists('nuvi_estado_interes_simple_cliente')) {
    function nuvi_estado_interes_simple_cliente($conexion, $idCliente, $bloquear = false)
    {
        if (!nuvi_interes_simple_disponible($conexion)) {
            return ['configuracion_lista' => false, 'aplicable' => false, 'cargo' => 0.0];
        }

        $sql = 'SELECT saldo_actual, fecha_inicio_interes, saldo_base_interes, semanas_interes_aplicadas FROM clientes WHERE id_cliente = ? LIMIT 1' . ($bloquear ? ' FOR UPDATE' : '');
        $stmt = mysqli_prepare($conexion, $sql);
        if (!$stmt) {
            return ['configuracion_lista' => true, 'aplicable' => false, 'cargo' => 0.0, 'error' => 'No se pudo leer el estado de interés'];
        }
        mysqli_stmt_bind_param($stmt, 'i', $idCliente);
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);
        $cliente = $resultado ? mysqli_fetch_assoc($resultado) : null;
        if (!$cliente) {
            return ['configuracion_lista' => true, 'aplicable' => false, 'cargo' => 0.0, 'error' => 'Cliente no encontrado'];
        }

        $estado = nuvi_calcular_interes_simple_libreta(
            $cliente['saldo_actual'] ?? 0,
            $cliente['saldo_base_interes'] ?? 0,
            $cliente['fecha_inicio_interes'] ?? null,
            $cliente['semanas_interes_aplicadas'] ?? 0
        );
        $estado['configuracion_lista'] = true;
        $estado['saldo_actual'] = round((float) ($cliente['saldo_actual'] ?? 0), 2);
        return $estado;
    }
}

if (!function_exists('nuvi_reiniciar_interes_simple_libreta')) {
    /** Un abono o ajuste inicia de nuevo el plazo sobre el saldo restante. */
    function nuvi_reiniciar_interes_simple_libreta($conexion, $idCliente, $saldoNuevo)
    {
        if (!nuvi_interes_simple_disponible($conexion)) {
            return ['configuracion_lista' => false, 'fecha_inicio' => null, 'saldo_base' => 0.0];
        }

        $saldoBase = round(max(0, (float) $saldoNuevo), 2);
        $fechaInicio = $saldoBase > 0 ? date('Y-m-d') : null;
        $semanas = 0;
        if ($fechaInicio === null) {
            $stmt = mysqli_prepare($conexion, 'UPDATE clientes SET fecha_inicio_interes = NULL, saldo_base_interes = ?, semanas_interes_aplicadas = ? WHERE id_cliente = ?');
            if (!$stmt) {
                throw new Exception('No se pudo preparar el plazo de interés');
            }
            mysqli_stmt_bind_param($stmt, 'dii', $saldoBase, $semanas, $idCliente);
        } else {
            $stmt = mysqli_prepare($conexion, 'UPDATE clientes SET fecha_inicio_interes = ?, saldo_base_interes = ?, semanas_interes_aplicadas = ? WHERE id_cliente = ?');
            if (!$stmt) {
                throw new Exception('No se pudo preparar el plazo de interés');
            }
            mysqli_stmt_bind_param($stmt, 'sdii', $fechaInicio, $saldoBase, $semanas, $idCliente);
        }
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('No se pudo actualizar el plazo de interés');
        }
        return ['configuracion_lista' => true, 'fecha_inicio' => $fechaInicio, 'saldo_base' => $saldoBase, 'semanas_aplicadas' => 0];
    }
}

if (!function_exists('nuvi_registrar_deuda_interes_simple_libreta')) {
    /** Incorpora una nueva venta fiada a la base simple sin borrar el plazo vigente. */
    function nuvi_registrar_deuda_interes_simple_libreta($conexion, $idCliente, $saldoAnterior, $montoDeuda, $saldoNuevo)
    {
        if (!nuvi_interes_simple_disponible($conexion)) {
            return ['configuracion_lista' => false, 'fecha_inicio' => null, 'saldo_base' => 0.0];
        }

        $saldoAnterior = round((float) $saldoAnterior, 2);
        $montoDeuda = round(max(0, (float) $montoDeuda), 2);
        $saldoNuevo = round((float) $saldoNuevo, 2);
        if ($saldoNuevo <= 0) {
            return nuvi_reiniciar_interes_simple_libreta($conexion, $idCliente, 0);
        }
        if ($saldoAnterior <= 0) {
            return nuvi_reiniciar_interes_simple_libreta($conexion, $idCliente, $saldoNuevo);
        }

        $estado = nuvi_estado_interes_simple_cliente($conexion, $idCliente, false);
        $baseActual = max(0, (float) ($estado['saldo_base'] ?? 0));
        $fechaInicio = $estado['fecha_inicio'] ?? null;
        $semanas = max(0, (int) ($estado['semanas_aplicadas'] ?? 0));
        if (!$fechaInicio || $baseActual <= 0) {
            return nuvi_reiniciar_interes_simple_libreta($conexion, $idCliente, $saldoNuevo);
        }

        $baseNuevo = round($baseActual + $montoDeuda, 2);
        $stmt = mysqli_prepare($conexion, 'UPDATE clientes SET saldo_base_interes = ? WHERE id_cliente = ?');
        if (!$stmt) {
            throw new Exception('No se pudo actualizar la base de interés');
        }
        mysqli_stmt_bind_param($stmt, 'di', $baseNuevo, $idCliente);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('No se pudo actualizar la base de interés');
        }
        return ['configuracion_lista' => true, 'fecha_inicio' => $fechaInicio, 'saldo_base' => $baseNuevo, 'semanas_aplicadas' => $semanas];
    }
}

if (!function_exists('nuvi_aplicar_interes_simple_libreta')) {
    function nuvi_aplicar_interes_simple_libreta($conexion, $idCliente, $idUsuario = 0)
    {
        $idCliente = (int) $idCliente;
        if ($idCliente <= 0) {
            return ['aplicado' => false, 'cargo' => 0.0, 'configuracion_lista' => nuvi_interes_simple_disponible($conexion)];
        }
        if (!nuvi_interes_simple_disponible($conexion)) {
            return ['aplicado' => false, 'cargo' => 0.0, 'configuracion_lista' => false];
        }

        mysqli_begin_transaction($conexion);
        try {
            $estado = nuvi_estado_interes_simple_cliente($conexion, $idCliente, true);
            if (!empty($estado['error'])) {
                throw new Exception((string) $estado['error']);
            }
            if (empty($estado['aplicable'])) {
                mysqli_commit($conexion);
                return $estado + ['aplicado' => false];
            }

            $saldoAnterior = (float) $estado['saldo_actual'];
            $cargo = (float) $estado['cargo'];
            $saldoNuevo = round($saldoAnterior + $cargo, 2);
            $semanas = (int) $estado['semanas'];
            $stmtSaldo = mysqli_prepare($conexion, 'UPDATE clientes SET saldo_actual = ?, semanas_interes_aplicadas = ? WHERE id_cliente = ?');
            if (!$stmtSaldo) {
                throw new Exception('No se pudo actualizar el saldo con interés');
            }
            mysqli_stmt_bind_param($stmtSaldo, 'dii', $saldoNuevo, $semanas, $idCliente);
            if (!mysqli_stmt_execute($stmtSaldo)) {
                throw new Exception('No se pudo actualizar el saldo con interés');
            }

            $tieneUsuario = mysqli_query($conexion, "SHOW COLUMNS FROM libreta_digital LIKE 'id_usuario'");
            $usaUsuario = $tieneUsuario && mysqli_num_rows($tieneUsuario) > 0;
            $nota = NUVI_INTERES_SIMPLE_REGLA
                . '|base=' . number_format((float) $estado['saldo_base'], 2, '.', '')
                . '|dias=' . (int) $estado['dias']
                . '|semanas_desde=' . (int) $estado['semanas_aplicadas']
                . '|semanas_hasta=' . $semanas
                . '|porcentaje=' . number_format((float) $estado['porcentaje_pendiente'], 2, '.', '')
                . '|cargo=' . number_format($cargo, 2, '.', '')
                . '|inicio=' . $estado['fecha_inicio']
                . '|saldo_anterior=' . number_format($saldoAnterior, 2, '.', '')
                . '|saldo_nuevo=' . number_format($saldoNuevo, 2, '.', '');
            $tipo = nuvi_resolver_tipo_movimiento_libreta($conexion, 'recargo');
            $stmtMovimiento = $usaUsuario
                ? mysqli_prepare($conexion, 'INSERT INTO libreta_digital (id_cliente, id_usuario, tipo_movimiento, monto, notas) VALUES (?, ?, ?, ?, ?)')
                : mysqli_prepare($conexion, 'INSERT INTO libreta_digital (id_cliente, tipo_movimiento, monto, notas) VALUES (?, ?, ?, ?)');
            if (!$stmtMovimiento) {
                throw new Exception('No se pudo registrar el interés de libreta');
            }
            if ($usaUsuario) {
                mysqli_stmt_bind_param($stmtMovimiento, 'iisds', $idCliente, $idUsuario, $tipo, $cargo, $nota);
            } else {
                mysqli_stmt_bind_param($stmtMovimiento, 'isds', $idCliente, $tipo, $cargo, $nota);
            }
            if (!mysqli_stmt_execute($stmtMovimiento)) {
                throw new Exception('No se pudo registrar el interés de libreta');
            }
            if (!mysqli_commit($conexion)) {
                throw new Exception('No se pudo confirmar el interés de libreta');
            }

            nuviRegistrarAuditoria($conexion, 'libreta_interes_simple', 'cliente', $idCliente, 'Interés simple de libreta por $' . number_format($cargo, 2, '.', ''), [
                'base' => (float) $estado['saldo_base'],
                'dias' => (int) $estado['dias'],
                'semanas_desde' => (int) $estado['semanas_aplicadas'],
                'semanas_hasta' => $semanas,
                'porcentaje' => (float) $estado['porcentaje_pendiente'],
                'cargo' => $cargo,
                'saldo_anterior' => $saldoAnterior,
                'saldo_nuevo' => $saldoNuevo,
            ]);
            return $estado + ['aplicado' => true, 'saldo_nuevo' => $saldoNuevo];
        } catch (Throwable $e) {
            mysqli_rollback($conexion);
            return ['aplicado' => false, 'cargo' => 0.0, 'configuracion_lista' => true, 'error' => $e->getMessage()];
        }
    }
}

/* Compatibilidad de integraciones antiguas: ya no cobra $10 fijo; usa la regla vigente. */
if (!function_exists('nuvi_aplicar_recargo_semanal_libreta')) {
    function nuvi_aplicar_recargo_semanal_libreta($conexion, $idCliente, $idUsuario = 0)
    {
        return nuvi_aplicar_interes_simple_libreta($conexion, $idCliente, $idUsuario);
    }
}
if (!function_exists('nuvi_aplicar_recargo_diario_libreta')) {
    function nuvi_aplicar_recargo_diario_libreta($conexion, $idCliente, $idUsuario = 0)
    {
        return nuvi_aplicar_interes_simple_libreta($conexion, $idCliente, $idUsuario);
    }
}
?>
