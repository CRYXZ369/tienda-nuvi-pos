<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('conexion.php');
require_once('operacion_inventario_utils.php');
require_once('auditoria_utils.php');

function volverProveedor(string $estado): void
{
    header('Location: abastecimiento.php?estado=' . rawurlencode($estado));
    exit;
}

if (!nuviOperacionTablaExiste($conexion, 'proveedores')) {
    volverProveedor('estructura_pendiente');
}

$nombre = trim((string) ($_POST['nombre'] ?? ''));
$contacto = trim((string) ($_POST['contacto'] ?? ''));
$telefono = trim((string) ($_POST['telefono'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$notas = trim((string) ($_POST['notas'] ?? ''));

if ($nombre === '' || mb_strlen($nombre) > 120 || mb_strlen($contacto) > 120 || mb_strlen($telefono) > 40 || mb_strlen($email) > 150 || mb_strlen($notas) > 500) {
    volverProveedor('proveedor_invalido');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    volverProveedor('correo_invalido');
}

try {
    $stmt = mysqli_prepare($conexion, 'INSERT INTO proveedores (nombre, contacto, telefono, email, notas) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('No se pudo preparar el proveedor.');
    mysqli_stmt_bind_param($stmt, 'sssss', $nombre, $contacto, $telefono, $email, $notas);
    if (!mysqli_stmt_execute($stmt)) {
        if (mysqli_stmt_errno($stmt) === 1062) volverProveedor('proveedor_duplicado');
        throw new Exception(mysqli_stmt_error($stmt));
    }
    $idProveedor = (int) mysqli_insert_id($conexion);
    nuviRegistrarAuditoria($conexion, 'proveedor_creado', 'proveedor', $idProveedor, 'Proveedor registrado: ' . $nombre);
    volverProveedor('proveedor_guardado');
} catch (Throwable $e) {
    error_log('guardar_proveedor: ' . $e->getMessage());
    volverProveedor('error_proveedor');
}
?>
