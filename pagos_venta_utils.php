<?php
/**
 * Desglose de cobros de una venta.
 *
 * `ventas.total` siempre conserva el total de los productos. Esta tabla
 * registra cómo se cubrió ese total y permite reimprimir un ticket fiel: el
 * efectivo recibido, el cambio y las partes de un pago mixto no se pierden al
 * cerrar o recargar el POS.
 */

function nuviPagosVentaTablaExiste(mysqli $conexion): bool
{
    static $existe = null;
    if ($existe !== null) {
        return $existe;
    }

    $resultado = mysqli_query($conexion, "SHOW TABLES LIKE 'pagos_venta'");
    $existe = $resultado instanceof mysqli_result && mysqli_num_rows($resultado) > 0;
    return $existe;
}

function nuviAsegurarTablaPagosVenta(mysqli $conexion): void
{
    if (nuviPagosVentaTablaExiste($conexion)) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS pagos_venta (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_venta INT UNSIGNED NOT NULL,
        metodo VARCHAR(20) NOT NULL,
        monto DECIMAL(12,2) NOT NULL,
        monto_recibido DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        cambio DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        id_terminal_pago INT UNSIGNED NOT NULL DEFAULT 0,
        terminal_nombre VARCHAR(80) NOT NULL DEFAULT '',
        monto_cobrado_tarjeta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        recargo_cliente_tarjeta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pagos_venta_id_venta (id_venta),
        KEY idx_pagos_venta_metodo (metodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conexion, $sql)) {
        throw new RuntimeException('No se pudo preparar el desglose de pagos: ' . mysqli_error($conexion));
    }

}

/** @param array<int, array<string, mixed>> $pagos */
function nuviGuardarPagosVenta(mysqli $conexion, int $idVenta, array $pagos): void
{
    if ($idVenta <= 0 || !$pagos) {
        throw new InvalidArgumentException('El desglose de pago es inválido.');
    }

    $stmt = mysqli_prepare(
        $conexion,
        'INSERT INTO pagos_venta
            (id_venta, metodo, monto, monto_recibido, cambio, id_terminal_pago, terminal_nombre, monto_cobrado_tarjeta, recargo_cliente_tarjeta)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar el desglose de pagos.');
    }

    foreach ($pagos as $pago) {
        $metodo = trim((string) ($pago['metodo'] ?? ''));
        $monto = round((float) ($pago['monto'] ?? 0), 2);
        $montoRecibido = round((float) ($pago['monto_recibido'] ?? 0), 2);
        $cambio = round((float) ($pago['cambio'] ?? 0), 2);
        $idTerminal = max(0, (int) ($pago['id_terminal_pago'] ?? 0));
        $terminalNombre = substr(trim((string) ($pago['terminal_nombre'] ?? '')), 0, 80);
        $montoCobrado = round((float) ($pago['monto_cobrado_tarjeta'] ?? 0), 2);
        $recargo = round((float) ($pago['recargo_cliente_tarjeta'] ?? 0), 2);
        if (!in_array($metodo, ['efectivo', 'tarjeta', 'transferencia', 'otro'], true) || $monto <= 0) {
            throw new InvalidArgumentException('El desglose de pago contiene un método o importe inválido.');
        }
        if ($montoRecibido < 0 || $cambio < 0 || $montoCobrado < 0 || $recargo < 0) {
            throw new InvalidArgumentException('El desglose de pago contiene importes inválidos.');
        }

        mysqli_stmt_bind_param(
            $stmt,
            'isdddisdd',
            $idVenta,
            $metodo,
            $monto,
            $montoRecibido,
            $cambio,
            $idTerminal,
            $terminalNombre,
            $montoCobrado,
            $recargo
        );
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('No se pudo guardar el desglose de pago.');
        }
    }
    mysqli_stmt_close($stmt);
}

/** @return array<int, array<string, mixed>> */
function nuviObtenerPagosVenta(mysqli $conexion, int $idVenta): array
{
    if ($idVenta <= 0 || !nuviPagosVentaTablaExiste($conexion)) {
        return [];
    }

    $stmt = mysqli_prepare(
        $conexion,
        'SELECT metodo, monto, monto_recibido, cambio, id_terminal_pago, terminal_nombre, monto_cobrado_tarjeta, recargo_cliente_tarjeta
         FROM pagos_venta WHERE id_venta = ? ORDER BY id ASC'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $idVenta);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [];
    }
    $resultado = mysqli_stmt_get_result($stmt);
    $pagos = [];
    while ($resultado && ($fila = mysqli_fetch_assoc($resultado))) {
        $pagos[] = $fila;
    }
    mysqli_stmt_close($stmt);
    return $pagos;
}
