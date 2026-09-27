<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('conexion.php');
require_once('operacion_inventario_utils.php');
require_once('auditoria_utils.php');

function volverCompra(string $estado): void
{
    header('Location: abastecimiento.php?estado=' . rawurlencode($estado));
    exit;
}

if (!nuviOperacionInventarioListo($conexion)) {
    volverCompra('estructura_pendiente');
}
$condicionProductoActivo = nuviOperacionColumnaExiste($conexion, 'productos', 'activo')
    ? '(activo IS NULL OR activo = 1)'
    : '1 = 1';

$idProveedor = (int) ($_POST['id_proveedor'] ?? 0);
$folio = trim((string) ($_POST['folio'] ?? ''));
$notas = trim((string) ($_POST['notas'] ?? ''));
$fechaEntrada = trim((string) ($_POST['fecha_compra'] ?? ''));
$ids = $_POST['producto_id'] ?? [];
$cantidades = $_POST['cantidad'] ?? [];
$costos = $_POST['costo_unitario'] ?? [];
$caducidades = $_POST['fecha_caducidad'] ?? [];
$lotes = $_POST['lote'] ?? [];

if (!is_array($ids) || !is_array($cantidades) || !is_array($costos)) volverCompra('compra_invalida');
if (mb_strlen($folio) > 80 || mb_strlen($notas) > 500) volverCompra('compra_invalida');

$fechaCompra = date('Y-m-d H:i:s');
if ($fechaEntrada !== '') {
    $fecha = DateTime::createFromFormat('Y-m-d\TH:i', $fechaEntrada);
    if (!$fecha || $fecha->format('Y-m-d\TH:i') !== $fechaEntrada) volverCompra('fecha_invalida');
    $fechaCompra = $fecha->format('Y-m-d H:i:s');
}

$lineas = [];
foreach ($ids as $indice => $idValor) {
    $idProducto = (int) $idValor;
    $cantidad = (float) str_replace(',', '.', (string) ($cantidades[$indice] ?? ''));
    $costo = (float) str_replace(',', '.', (string) ($costos[$indice] ?? ''));
    $caducidad = trim((string) ($caducidades[$indice] ?? ''));
    $lote = trim((string) ($lotes[$indice] ?? ''));
    if ($idProducto <= 0 && $cantidad == 0.0 && $costo == 0.0) continue;
    if ($idProducto <= 0 || $cantidad <= 0 || $costo < 0 || mb_strlen($lote) > 80) volverCompra('compra_invalida');
    if ($caducidad !== '') {
        $fechaCaducidad = DateTime::createFromFormat('Y-m-d', $caducidad);
        if (!$fechaCaducidad || $fechaCaducidad->format('Y-m-d') !== $caducidad) volverCompra('caducidad_invalida');
    }
    $lineas[] = compact('idProducto', 'cantidad', 'costo', 'caducidad', 'lote');
}
if (!$lineas) volverCompra('sin_productos');

try {
    mysqli_begin_transaction($conexion);

    if ($idProveedor > 0) {
        $stmtProveedor = mysqli_prepare($conexion, 'SELECT id FROM proveedores WHERE id = ? AND activo = 1 LIMIT 1');
        mysqli_stmt_bind_param($stmtProveedor, 'i', $idProveedor);
        mysqli_stmt_execute($stmtProveedor);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmtProveedor))) {
            throw new Exception('El proveedor seleccionado no está disponible.');
        }
    }

    $total = 0.0;
    foreach ($lineas as &$linea) {
        $linea['subtotal'] = round($linea['cantidad'] * $linea['costo'], 2);
        $total += $linea['subtotal'];
    }
    unset($linea);
    $total = round($total, 2);

    $idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
    $idProveedorDb = $idProveedor > 0 ? $idProveedor : null;
    $stmtCompra = mysqli_prepare($conexion, 'INSERT INTO compras (id_proveedor, id_usuario, fecha_compra, folio, total, notas) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmtCompra) throw new Exception('No se pudo preparar la compra.');
    mysqli_stmt_bind_param($stmtCompra, 'iissds', $idProveedorDb, $idUsuario, $fechaCompra, $folio, $total, $notas);
    if (!mysqli_stmt_execute($stmtCompra)) throw new Exception(mysqli_stmt_error($stmtCompra));
    $idCompra = (int) mysqli_insert_id($conexion);

    $stmtProducto = mysqli_prepare($conexion, "SELECT id, nombre FROM productos WHERE id = ? AND {$condicionProductoActivo} FOR UPDATE");
    $stmtStock = mysqli_prepare($conexion, 'UPDATE productos SET stock = stock + ?, precio_compra = ? WHERE id = ?');
    $stmtDetalle = mysqli_prepare($conexion, 'INSERT INTO compra_detalles (id_compra, id_producto, cantidad_recibida, costo_unitario, fecha_caducidad, lote, subtotal) VALUES (?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?)');
    $stmtLote = mysqli_prepare($conexion, 'INSERT INTO lotes_inventario (id_producto, id_compra_detalle, fecha_caducidad, lote, cantidad_inicial, cantidad_disponible, costo_unitario) VALUES (?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?)');
    $stmtMovimiento = mysqli_prepare($conexion, "INSERT INTO movimientos_inventario (id_producto, id_usuario, tipo, cantidad, costo_unitario, referencia_tipo, referencia_id, motivo, fecha_movimiento) VALUES (?, ?, 'compra', ?, ?, 'compra', ?, ?, ?)");
    if (!$stmtProducto || !$stmtStock || !$stmtDetalle || !$stmtLote || !$stmtMovimiento) {
        throw new Exception('No se pudo preparar el movimiento de inventario.');
    }

    foreach ($lineas as $linea) {
        mysqli_stmt_bind_param($stmtProducto, 'i', $linea['idProducto']);
        mysqli_stmt_execute($stmtProducto);
        $producto = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtProducto));
        if (!$producto) throw new Exception('Un producto de la compra ya no está disponible.');

        mysqli_stmt_bind_param($stmtStock, 'ddi', $linea['cantidad'], $linea['costo'], $linea['idProducto']);
        if (!mysqli_stmt_execute($stmtStock)) throw new Exception('No se pudo aumentar el inventario.');

        mysqli_stmt_bind_param($stmtDetalle, 'iiddssd', $idCompra, $linea['idProducto'], $linea['cantidad'], $linea['costo'], $linea['caducidad'], $linea['lote'], $linea['subtotal']);
        if (!mysqli_stmt_execute($stmtDetalle)) throw new Exception('No se pudo guardar el detalle de compra.');
        $idDetalle = (int) mysqli_insert_id($conexion);

        mysqli_stmt_bind_param($stmtLote, 'iissddd', $linea['idProducto'], $idDetalle, $linea['caducidad'], $linea['lote'], $linea['cantidad'], $linea['cantidad'], $linea['costo']);
        if (!mysqli_stmt_execute($stmtLote)) throw new Exception('No se pudo guardar el lote.');

        $motivo = 'Compra' . ($folio !== '' ? ' folio ' . $folio : '') . ': ' . $producto['nombre'];
        mysqli_stmt_bind_param($stmtMovimiento, 'iiddiss', $linea['idProducto'], $idUsuario, $linea['cantidad'], $linea['costo'], $idCompra, $motivo, $fechaCompra);
        if (!mysqli_stmt_execute($stmtMovimiento)) throw new Exception('No se pudo registrar el movimiento.');
    }

    mysqli_commit($conexion);
    nuviRegistrarAuditoria($conexion, 'compra_registrada', 'compra', $idCompra, 'Compra registrada por $' . number_format($total, 2, '.', ''), [
        'proveedor_id' => $idProveedor ?: null,
        'folio' => $folio,
        'total' => $total,
        'lineas' => count($lineas),
    ]);
    volverCompra('compra_guardada');
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    error_log('registrar_compra: ' . $e->getMessage());
    volverCompra('error_compra');
}
?>
