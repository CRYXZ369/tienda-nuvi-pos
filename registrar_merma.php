<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('conexion.php');
require_once('operacion_inventario_utils.php');
require_once('auditoria_utils.php');
require_once('abastecimiento_form_utils.php');

function volverMerma(string $estado): void
{
    header('Location: abastecimiento.php?estado=' . rawurlencode($estado));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !nuviConsumirTokenAbastecimiento('merma', $_POST['token_operacion'] ?? null)) {
    volverMerma('formulario_vencido');
}
if (!nuviOperacionInventarioListo($conexion)) volverMerma('estructura_pendiente');

$idProducto = (int) ($_POST['id_producto'] ?? 0);
$cantidad = (float) str_replace(',', '.', (string) ($_POST['cantidad'] ?? ''));
$motivo = trim((string) ($_POST['motivo'] ?? ''));
if ($idProducto <= 0 || $cantidad <= 0 || $motivo === '' || mb_strlen($motivo) > 255) volverMerma('merma_invalida');

try {
    mysqli_begin_transaction($conexion);
    $stmtProducto = mysqli_prepare($conexion, 'SELECT nombre, stock, precio_compra FROM productos WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($stmtProducto, 'i', $idProducto);
    mysqli_stmt_execute($stmtProducto);
    $producto = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtProducto));
    if (!$producto) throw new Exception('Producto no encontrado.');
    if ((float) $producto['stock'] < $cantidad) {
        mysqli_rollback($conexion);
        volverMerma('merma_stock');
    }

    $stmtStock = mysqli_prepare($conexion, 'UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?');
    mysqli_stmt_bind_param($stmtStock, 'did', $cantidad, $idProducto, $cantidad);
    if (!mysqli_stmt_execute($stmtStock) || mysqli_stmt_affected_rows($stmtStock) !== 1) throw new Exception('El stock cambió antes de registrar la merma.');
    nuviConsumirLotesInventario($conexion, $idProducto, $cantidad);

    $idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
    $costo = max(0, (float) $producto['precio_compra']);
    $cantidadNegativa = -$cantidad;
    $stmtMovimiento = mysqli_prepare($conexion, "INSERT INTO movimientos_inventario (id_producto, id_usuario, tipo, cantidad, costo_unitario, referencia_tipo, motivo) VALUES (?, ?, 'merma', ?, ?, 'merma', ?)");
    mysqli_stmt_bind_param($stmtMovimiento, 'iidds', $idProducto, $idUsuario, $cantidadNegativa, $costo, $motivo);
    if (!mysqli_stmt_execute($stmtMovimiento)) throw new Exception('No se pudo guardar la merma.');
    $idMerma = (int) mysqli_insert_id($conexion);

    mysqli_commit($conexion);
    nuviRegistrarAuditoria($conexion, 'merma_registrada', 'movimiento_inventario', $idMerma, 'Merma de ' . $producto['nombre'], [
        'producto_id' => $idProducto,
        'cantidad' => $cantidad,
        'costo_estimado' => round($cantidad * $costo, 2),
        'motivo' => $motivo,
    ]);
    volverMerma('merma_guardada');
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    error_log('registrar_merma: ' . $e->getMessage());
    volverMerma('error_merma');
}
?>
