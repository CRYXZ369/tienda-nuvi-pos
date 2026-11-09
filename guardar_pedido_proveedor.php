<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('conexion.php');
require_once('operacion_inventario_utils.php');
require_once('abastecimiento_form_utils.php');
require_once('pedidos_proveedor_utils.php');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function volverPedidos(int $proveedor, string $estado): void
{
    header('Location: pedidos_proveedor.php?proveedor=' . $proveedor . '&estado=' . rawurlencode($estado));
    exit;
}

$id = (int) ($_POST['proveedor'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !nuviConsumirTokenAbastecimiento('pedido_proveedor', $_POST['token_operacion'] ?? null)) {
    volverPedidos($id, 'formulario_vencido');
}
if ($id <= 0 || !nuviOperacionTablaExiste($conexion, 'proveedor_agenda') || !nuviOperacionTablaExiste($conexion, 'proveedor_productos')) {
    volverPedidos($id, 'invalido');
}

try {
    mysqli_begin_transaction($conexion);
    $stmt = mysqli_prepare($conexion, 'SELECT id FROM proveedores WHERE id = ? AND activo = 1 FOR UPDATE');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) throw new InvalidArgumentException('Proveedor no disponible.');
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'agenda') {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $contacto = trim((string) ($_POST['contacto'] ?? ''));
        $telefono = trim((string) ($_POST['telefono'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $notas = trim((string) ($_POST['notas'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 120 || mb_strlen($contacto) > 120 || mb_strlen($telefono) > 40
            || mb_strlen($email) > 150 || mb_strlen($notas) > 500 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('Datos de proveedor inválidos.');
        }
        $preventa = implode(',', nuviDiasProveedor($_POST['preventa'] ?? []));
        $entrega = implode(',', nuviDiasProveedor($_POST['entrega'] ?? []));
        $stmt = mysqli_prepare($conexion, 'UPDATE proveedores SET nombre=?, contacto=?, telefono=?, email=?, notas=? WHERE id=?');
        mysqli_stmt_bind_param($stmt, 'sssssi', $nombre, $contacto, $telefono, $email, $notas, $id);
        mysqli_stmt_execute($stmt);
        $stmt = mysqli_prepare($conexion, 'INSERT INTO proveedor_agenda (id_proveedor, dias_preventa, dias_entrega) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE dias_preventa=VALUES(dias_preventa), dias_entrega=VALUES(dias_entrega)');
        mysqli_stmt_bind_param($stmt, 'iss', $id, $preventa, $entrega);
        mysqli_stmt_execute($stmt);
    } elseif ($accion === 'historial') {
        $stmt = mysqli_prepare($conexion, 'INSERT IGNORE INTO proveedor_productos (id_proveedor, id_producto)
            SELECT DISTINCT c.id_proveedor, d.id_producto FROM compras c JOIN compra_detalles d ON d.id_compra=c.id
            JOIN productos p ON p.id=d.id_producto WHERE c.id_proveedor=? AND (p.activo IS NULL OR p.activo=1)');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
    } elseif ($accion === 'producto' || $accion === 'agregar' || $accion === 'quitar') {
        $producto = (int) ($_POST['producto'] ?? 0);
        if ($producto <= 0) throw new InvalidArgumentException('Producto inválido.');
        if ($accion === 'quitar') {
            $stmt = mysqli_prepare($conexion, 'DELETE FROM proveedor_productos WHERE id_proveedor=? AND id_producto=?');
            mysqli_stmt_bind_param($stmt, 'ii', $id, $producto);
            mysqli_stmt_execute($stmt);
        } else {
            $stmt = mysqli_prepare($conexion, 'SELECT unidad_medida FROM productos WHERE id=? AND (activo IS NULL OR activo=1)');
            mysqli_stmt_bind_param($stmt, 'i', $producto);
            mysqli_stmt_execute($stmt);
            $fila = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if (!$fila) throw new InvalidArgumentException('Producto no disponible.');
            $paquete = trim((string) ($_POST['paquete'] ?? ''));
            if ($paquete !== '' && (!preg_match('/^\d{1,8}(?:\.\d{1,3})?$/D', $paquete) || (float) $paquete <= 0
                || (strtolower(trim((string) $fila['unidad_medida'])) === 'pza' && floor((float) $paquete) != (float) $paquete))) {
                throw new InvalidArgumentException('Presentación inválida.');
            }
            $paqueteDb = $paquete === '' ? null : $paquete;
            $sql = $accion === 'agregar'
                ? 'INSERT IGNORE INTO proveedor_productos (id_proveedor, id_producto, unidades_paquete) VALUES (?, ?, ?)'
                : 'UPDATE proveedor_productos SET unidades_paquete=? WHERE id_proveedor=? AND id_producto=?';
            $stmt = mysqli_prepare($conexion, $sql);
            if ($accion === 'agregar') {
                mysqli_stmt_bind_param($stmt, 'iis', $id, $producto, $paqueteDb);
            } else {
                mysqli_stmt_bind_param($stmt, 'sii', $paqueteDb, $id, $producto);
            }
            mysqli_stmt_execute($stmt);
        }
    } else {
        throw new InvalidArgumentException('Acción inválida.');
    }
    mysqli_commit($conexion);
    volverPedidos($id, 'guardado');
} catch (InvalidArgumentException $e) {
    mysqli_rollback($conexion);
    volverPedidos($id, 'invalido');
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    error_log('guardar_pedido_proveedor: ' . $e->getMessage());
    volverPedidos($id, 'error');
}
