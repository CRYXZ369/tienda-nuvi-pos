<?php
/** Utilidades opcionales para compras, lotes, merma y costo histórico. */

function nuviOperacionTablaExiste(mysqli $conexion, string $tabla): bool
{
    static $cache = [];
    $clave = strtolower($tabla);
    if (array_key_exists($clave, $cache)) {
        return $cache[$clave];
    }

    $tablaSegura = mysqli_real_escape_string($conexion, $tabla);
    $resultado = mysqli_query($conexion, "SHOW TABLES LIKE '{$tablaSegura}'");
    return $cache[$clave] = $resultado instanceof mysqli_result && mysqli_num_rows($resultado) > 0;
}

function nuviOperacionColumnaExiste(mysqli $conexion, string $tabla, string $columna): bool
{
    static $cache = [];
    $clave = strtolower($tabla . '.' . $columna);
    if (array_key_exists($clave, $cache)) {
        return $cache[$clave];
    }

    $tablaSegura = str_replace('`', '``', $tabla);
    $columnaSegura = mysqli_real_escape_string($conexion, $columna);
    $resultado = mysqli_query($conexion, "SHOW COLUMNS FROM `{$tablaSegura}` LIKE '{$columnaSegura}'");
    return $cache[$clave] = $resultado instanceof mysqli_result && mysqli_num_rows($resultado) > 0;
}

function nuviOperacionInventarioListo(mysqli $conexion): bool
{
    foreach (['proveedores', 'compras', 'compra_detalles', 'lotes_inventario', 'movimientos_inventario'] as $tabla) {
        if (!nuviOperacionTablaExiste($conexion, $tabla)) return false;
    }
    return nuviOperacionColumnaExiste($conexion, 'detalle_ventas', 'costo_unitario');
}

/**
 * Descuenta lotes por fecha de caducidad (FIFO por caducidad) hasta donde haya
 * stock trazado. El inventario anterior a esta mejora sigue siendo válido, pero
 * no se inventan lotes retroactivos para no falsear sus fechas.
 */
function nuviConsumirLotesInventario(mysqli $conexion, int $idProducto, float $cantidad): void
{
    static $lotesDisponibles = null;
    if ($lotesDisponibles === null) {
        $lotesDisponibles = nuviOperacionTablaExiste($conexion, 'lotes_inventario');
    }
    if ($cantidad <= 0 || !$lotesDisponibles) return;

    $stmt = mysqli_prepare(
        $conexion,
        "SELECT id, cantidad_disponible
         FROM lotes_inventario
         WHERE id_producto = ? AND cantidad_disponible > 0
         ORDER BY fecha_caducidad IS NULL ASC, fecha_caducidad ASC, id ASC
         FOR UPDATE"
    );
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'i', $idProducto);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    $restante = $cantidad;
    $lotes = [];
    while ($fila = $resultado ? mysqli_fetch_assoc($resultado) : null) {
        $lotes[] = $fila;
    }
    if (!$lotes) return;

    $actualizar = mysqli_prepare(
        $conexion,
        'UPDATE lotes_inventario SET cantidad_disponible = cantidad_disponible - ? WHERE id = ? AND cantidad_disponible >= ?'
    );
    if (!$actualizar) return;
    foreach ($lotes as $lote) {
        if ($restante <= 0.000001) break;
        $disponible = (float) $lote['cantidad_disponible'];
        $consumo = min($restante, $disponible);
        $idLote = (int) $lote['id'];
        mysqli_stmt_bind_param($actualizar, 'did', $consumo, $idLote, $consumo);
        if (mysqli_stmt_execute($actualizar) && mysqli_stmt_affected_rows($actualizar) === 1) {
            $restante -= $consumo;
        }
    }
}

/**
 * Devuelve disponibilidad a lotes que ya habían sido consumidos, sin superar
 * su cantidad inicial. Se usa al editar o devolver una venta: el sistema no
 * tenía asignación histórica por lote antes de esta mejora, así que sólo se
 * restaura donde existe capacidad comprobable y nunca se inventan lotes.
 */
function nuviRestaurarLotesInventario(mysqli $conexion, int $idProducto, float $cantidad): void
{
    static $lotesDisponibles = null;
    if ($lotesDisponibles === null) {
        $lotesDisponibles = nuviOperacionTablaExiste($conexion, 'lotes_inventario');
    }
    if ($cantidad <= 0 || !$lotesDisponibles) return;

    $stmt = mysqli_prepare(
        $conexion,
        "SELECT id, cantidad_inicial, cantidad_disponible
         FROM lotes_inventario
         WHERE id_producto = ? AND cantidad_disponible < cantidad_inicial
         ORDER BY fecha_caducidad IS NULL ASC, fecha_caducidad ASC, id ASC
         FOR UPDATE"
    );
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'i', $idProducto);
    if (!mysqli_stmt_execute($stmt)) return;
    $resultado = mysqli_stmt_get_result($stmt);
    if (!$resultado) return;

    $actualizar = mysqli_prepare(
        $conexion,
        'UPDATE lotes_inventario SET cantidad_disponible = cantidad_disponible + ? WHERE id = ? AND cantidad_disponible + ? <= cantidad_inicial'
    );
    if (!$actualizar) return;

    $restante = $cantidad;
    while ($restante > 0.000001 && ($lote = mysqli_fetch_assoc($resultado))) {
        $disponible = (float) $lote['cantidad_disponible'];
        $inicial = (float) $lote['cantidad_inicial'];
        $capacidad = max(0.0, $inicial - $disponible);
        if ($capacidad <= 0.000001) continue;

        $reposicion = min($restante, $capacidad);
        $idLote = (int) $lote['id'];
        mysqli_stmt_bind_param($actualizar, 'did', $reposicion, $idLote, $reposicion);
        if (mysqli_stmt_execute($actualizar) && mysqli_stmt_affected_rows($actualizar) === 1) {
            $restante -= $reposicion;
        }
    }
}
?>
