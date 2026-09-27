<?php
/**
 * Reglas compartidas para cobros con tarjeta.
 *
 * El total de una venta siempre corresponde a los productos. El cargo de la
 * terminal se calcula aparte mediante gross-up, de modo que la comisión la
 * absorbe el cliente y nunca se descuenta del importe de la tienda.
 */

function nuviConfigComisionPredeterminada(): array
{
    return [
        'id' => 0,
        'nombre' => 'Terminal principal',
        'comision_porcentaje' => 3.50,
        'iva_porcentaje' => 16.00,
        'cargo_fijo' => 0.00,
        'tiempo_acreditacion' => 'Al día siguiente hábil',
        'activo' => 1,
        'es_predeterminada' => 1,
    ];
}

function nuviTablaExiste(mysqli $conexion, string $tabla): bool
{
    $tablaSegura = mysqli_real_escape_string($conexion, $tabla);
    $resultado = mysqli_query($conexion, "SHOW TABLES LIKE '{$tablaSegura}'");
    return $resultado instanceof mysqli_result && mysqli_num_rows($resultado) > 0;
}

function nuviColumnaExiste(mysqli $conexion, string $tabla, string $columna): bool
{
    $tablaSegura = str_replace('`', '``', $tabla);
    $columnaSegura = mysqli_real_escape_string($conexion, $columna);
    $resultado = mysqli_query($conexion, "SHOW COLUMNS FROM `{$tablaSegura}` LIKE '{$columnaSegura}'");
    return $resultado instanceof mysqli_result && mysqli_num_rows($resultado) > 0;
}

function nuviNormalizarTerminal(array $fila): array
{
    $base = nuviConfigComisionPredeterminada();
    return [
        'id' => max(0, (int) ($fila['id'] ?? 0)),
        'nombre' => trim((string) ($fila['nombre'] ?? '')) ?: $base['nombre'],
        'comision_porcentaje' => max(0, (float) ($fila['comision_porcentaje'] ?? $base['comision_porcentaje'])),
        'iva_porcentaje' => max(0, (float) ($fila['iva_porcentaje'] ?? $base['iva_porcentaje'])),
        'cargo_fijo' => max(0, (float) ($fila['cargo_fijo'] ?? $base['cargo_fijo'])),
        'tiempo_acreditacion' => trim((string) ($fila['tiempo_acreditacion'] ?? '')) ?: $base['tiempo_acreditacion'],
        'activo' => !empty($fila['activo']) ? 1 : 0,
        'es_predeterminada' => !empty($fila['es_predeterminada']) ? 1 : 0,
    ];
}

function nuviEsquemaComisionesListo(mysqli $conexion): bool
{
    if (!nuviTablaExiste($conexion, 'terminales_pago')) {
        return false;
    }

    foreach (['comision_tarjeta', 'monto_neto_tarjeta', 'monto_cobrado_tarjeta', 'recargo_cliente_tarjeta', 'id_terminal_pago', 'terminal_nombre'] as $columna) {
        if (!nuviColumnaExiste($conexion, 'ventas', $columna)) {
            return false;
        }
    }

    return true;
}

/** @return array<int, array<string, mixed>> */
function nuviObtenerTerminalesPago(mysqli $conexion, bool $soloActivas = true): array
{
    if (!nuviTablaExiste($conexion, 'terminales_pago')) {
        return [];
    }

    $where = $soloActivas ? 'WHERE activo = 1' : '';
    $resultado = mysqli_query(
        $conexion,
        "SELECT id, nombre, comision_porcentaje, iva_porcentaje, cargo_fijo, tiempo_acreditacion, activo, es_predeterminada
         FROM terminales_pago {$where}
         ORDER BY es_predeterminada DESC, nombre ASC, id ASC"
    );
    if (!$resultado instanceof mysqli_result) {
        return [];
    }

    $terminales = [];
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $terminales[] = nuviNormalizarTerminal($fila);
    }
    return $terminales;
}

function nuviObtenerTerminalPago(mysqli $conexion, int $idTerminal = 0, bool $soloActiva = true): ?array
{
    if (!nuviTablaExiste($conexion, 'terminales_pago')) {
        return null;
    }

    if ($idTerminal > 0) {
        $sql = 'SELECT id, nombre, comision_porcentaje, iva_porcentaje, cargo_fijo, tiempo_acreditacion, activo, es_predeterminada
                FROM terminales_pago WHERE id = ?' . ($soloActiva ? ' AND activo = 1' : '') . ' LIMIT 1';
        $stmt = mysqli_prepare($conexion, $sql);
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $idTerminal);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $fila = $res ? mysqli_fetch_assoc($res) : null;
        return $fila ? nuviNormalizarTerminal($fila) : null;
    }

    $terminales = nuviObtenerTerminalesPago($conexion, $soloActiva);
    return $terminales[0] ?? null;
}

/** Compatibilidad para código anterior: devuelve la terminal predeterminada. */
function nuviObtenerConfigComision(mysqli $conexion): array
{
    $terminal = nuviObtenerTerminalPago($conexion);
    if ($terminal) {
        return $terminal;
    }

    $config = nuviConfigComisionPredeterminada();
    if (!nuviTablaExiste($conexion, 'config_comisiones')) {
        return $config;
    }

    $resultado = mysqli_query($conexion, 'SELECT comision_porcentaje, iva_porcentaje, cargo_fijo, tiempo_acreditacion FROM config_comisiones WHERE id = 1 LIMIT 1');
    $fila = $resultado instanceof mysqli_result ? mysqli_fetch_assoc($resultado) : null;
    return $fila ? nuviNormalizarTerminal($fila) : $config;
}

/**
 * Calcula el cargo final mediante gross-up:
 *   cargo - comisión(cargo) = total de los productos.
 */
function nuviCalcularCobroTerminal(float $totalProductos, array $config): array
{
    if ($totalProductos <= 0) {
        throw new InvalidArgumentException('El total de productos debe ser mayor a cero.');
    }

    $porcentaje = max(0, (float) ($config['comision_porcentaje'] ?? 0));
    $iva = max(0, (float) ($config['iva_porcentaje'] ?? 0));
    $cargoFijo = max(0, (float) ($config['cargo_fijo'] ?? 0));
    $tasaEfectiva = ($porcentaje / 100) * (1 + ($iva / 100));
    if ($tasaEfectiva >= 1) {
        throw new RuntimeException('La comisión configurada no permite calcular un cobro válido.');
    }

    $montoSinRedondear = ($totalProductos + $cargoFijo) / (1 - $tasaEfectiva);
    $montoCobrado = ceil(($montoSinRedondear * 100) - 0.000001) / 100;
    $comisionProcesador = round(($montoCobrado * $tasaEfectiva) + $cargoFijo, 2);
    $montoNeto = round($montoCobrado - $comisionProcesador, 2);

    return [
        'total_productos' => round($totalProductos, 2),
        'monto_cobrado_tarjeta' => $montoCobrado,
        'recargo_cliente_tarjeta' => round($montoCobrado - $totalProductos, 2),
        'comision_tarjeta' => $comisionProcesador,
        'monto_neto_tarjeta' => $montoNeto,
        'tasa_efectiva_porcentaje' => $tasaEfectiva * 100,
    ];
}
?>
