<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('conexion.php');
require_once('operacion_inventario_utils.php');
require_once('auditoria_utils.php');
require_once('abastecimiento_form_utils.php');

function volverCaducidad(string $estado): void
{
    header('Location: abastecimiento.php?estado=' . rawurlencode($estado));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !nuviConsumirTokenAbastecimiento('caducidad', $_POST['token_operacion'] ?? null)) {
    volverCaducidad('formulario_vencido');
}
if (!nuviOperacionInventarioListo($conexion)) volverCaducidad('estructura_pendiente');

$idProducto = (int) ($_POST['id_producto'] ?? 0);
$textoCantidad = str_replace(',', '.', trim((string) ($_POST['cantidad'] ?? '')));
$fechaTexto = trim((string) ($_POST['fecha_caducidad'] ?? ''));
$fecha = DateTime::createFromFormat('!Y-m-d', $fechaTexto);
if ($idProducto <= 0 || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D', $textoCantidad)
    || (float) $textoCantidad <= 0 || !$fecha || $fecha->format('Y-m-d') !== $fechaTexto) {
    volverCaducidad('caducidad_existente_invalida');
}
$cantidadMil = (int) round((float) $textoCantidad * 1000);

try {
    mysqli_begin_transaction($conexion);
    $stmt = mysqli_prepare($conexion, 'SELECT nombre, stock, precio_compra FROM productos WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($stmt, 'i', $idProducto);
    mysqli_stmt_execute($stmt);
    $producto = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$producto) throw new RuntimeException('Producto no encontrado.');

    $stmtLotes = mysqli_prepare($conexion, 'SELECT id, id_compra_detalle, lote, cantidad_disponible, costo_unitario, fecha_caducidad
        FROM lotes_inventario WHERE id_producto = ? AND cantidad_disponible > 0 ORDER BY id FOR UPDATE');
    mysqli_stmt_bind_param($stmtLotes, 'i', $idProducto);
    mysqli_stmt_execute($stmtLotes);
    $resultado = mysqli_stmt_get_result($stmtLotes);
    $trazadoMil = 0;
    $sinFechaMil = 0;
    $lotesSinFecha = [];
    while ($lote = mysqli_fetch_assoc($resultado)) {
        $disponibleMil = (int) round((float) $lote['cantidad_disponible'] * 1000);
        $trazadoMil += $disponibleMil;
        if ($lote['fecha_caducidad'] === null) {
            $sinFechaMil += $disponibleMil;
            $lote['disponible_mil'] = $disponibleMil;
            $lotesSinFecha[] = $lote;
        }
    }
    $stockMil = (int) round((float) $producto['stock'] * 1000);
    $sinLoteMil = max(0, $stockMil - $trazadoMil);
    if ($stockMil < $trazadoMil || $cantidadMil > $sinLoteMil + $sinFechaMil) {
        mysqli_rollback($conexion);
        volverCaducidad('caducidad_stock');
    }

    $insertar = mysqli_prepare($conexion, 'INSERT INTO lotes_inventario
        (id_producto, id_compra_detalle, fecha_caducidad, lote, cantidad_inicial, cantidad_disponible, costo_unitario)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $reducir = mysqli_prepare($conexion, 'UPDATE lotes_inventario SET cantidad_inicial = cantidad_inicial - ?,
        cantidad_disponible = cantidad_disponible - ? WHERE id = ? AND cantidad_disponible >= ?');
    $restante = $cantidadMil;
    $sinLote = min($restante, $sinLoteMil);
    if ($sinLote > 0) {
        $cantidad = number_format($sinLote / 1000, 3, '.', '');
        $detalle = null;
        $nombreLote = null;
        $costo = $producto['precio_compra'];
        mysqli_stmt_bind_param($insertar, 'iisssss', $idProducto, $detalle, $fechaTexto, $nombreLote, $cantidad, $cantidad, $costo);
        if (!mysqli_stmt_execute($insertar)) throw new RuntimeException('No se pudo registrar la fecha.');
        $restante -= $sinLote;
    }
    foreach ($lotesSinFecha as $lote) {
        if ($restante <= 0) break;
        $parte = min($restante, $lote['disponible_mil']);
        $cantidad = number_format($parte / 1000, 3, '.', '');
        $idLote = (int) $lote['id'];
        mysqli_stmt_bind_param($reducir, 'ssis', $cantidad, $cantidad, $idLote, $cantidad);
        if (!mysqli_stmt_execute($reducir) || mysqli_stmt_affected_rows($reducir) !== 1) {
            throw new RuntimeException('El lote cambió antes de registrar la fecha.');
        }
        $detalle = $lote['id_compra_detalle'];
        $nombreLote = $lote['lote'];
        $costo = $lote['costo_unitario'];
        mysqli_stmt_bind_param($insertar, 'iisssss', $idProducto, $detalle, $fechaTexto, $nombreLote, $cantidad, $cantidad, $costo);
        if (!mysqli_stmt_execute($insertar)) throw new RuntimeException('No se pudo registrar la fecha del lote.');
        $restante -= $parte;
    }
    if ($restante !== 0) throw new RuntimeException('No se pudo asignar toda la cantidad.');
    mysqli_commit($conexion);
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    error_log('registrar_caducidad: ' . $e->getMessage());
    volverCaducidad('error_caducidad');
}
try {
    nuviRegistrarAuditoria($conexion, 'caducidad_registrada', 'producto', $idProducto, 'Fecha registrada para ' . $producto['nombre'], [
        'cantidad' => $cantidadMil / 1000,
        'fecha_caducidad' => $fechaTexto,
    ]);
} catch (mysqli_sql_exception $e) {
    error_log('registrar_caducidad bitácora: ' . $e->getMessage());
}
volverCaducidad('caducidad_guardada');
