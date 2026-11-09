<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('conexion.php');
require_once('operacion_inventario_utils.php');
require_once('auditoria_utils.php');
require_once('abastecimiento_form_utils.php');
require_once('pedidos_proveedor_utils.php');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function volverProveedor(string $estado): void
{
    header('Location: abastecimiento.php?estado=' . rawurlencode($estado));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !nuviConsumirTokenAbastecimiento('proveedor', $_POST['token_operacion'] ?? null)) {
    volverProveedor('formulario_vencido');
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
    $preventa = nuviDiasProveedor($_POST['preventa'] ?? []);
    $entrega = nuviDiasProveedor($_POST['entrega'] ?? []);
    $agendaLista = nuviOperacionTablaExiste($conexion, 'proveedor_agenda');
    if (!$agendaLista && ($preventa || $entrega)) volverProveedor('estructura_pendiente');
    mysqli_begin_transaction($conexion);
    $stmt = mysqli_prepare($conexion, 'INSERT INTO proveedores (nombre, contacto, telefono, email, notas) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('No se pudo preparar el proveedor.');
    mysqli_stmt_bind_param($stmt, 'sssss', $nombre, $contacto, $telefono, $email, $notas);
    if (!mysqli_stmt_execute($stmt)) {
        if (mysqli_stmt_errno($stmt) === 1062) volverProveedor('proveedor_duplicado');
        throw new Exception(mysqli_stmt_error($stmt));
    }
    $idProveedor = (int) mysqli_insert_id($conexion);
    if ($agendaLista) {
        $preventaTexto = implode(',', $preventa);
        $entregaTexto = implode(',', $entrega);
        $stmtAgenda = mysqli_prepare($conexion, 'INSERT INTO proveedor_agenda (id_proveedor, dias_preventa, dias_entrega) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($stmtAgenda, 'iss', $idProveedor, $preventaTexto, $entregaTexto);
        mysqli_stmt_execute($stmtAgenda);
    }
    mysqli_commit($conexion);
} catch (InvalidArgumentException $e) {
    volverProveedor('proveedor_invalido');
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) volverProveedor('proveedor_duplicado');
    error_log('guardar_proveedor: ' . $e->getMessage());
    volverProveedor('error_proveedor');
}
try {
    nuviRegistrarAuditoria($conexion, 'proveedor_creado', 'proveedor', $idProveedor, 'Proveedor registrado: ' . $nombre);
} catch (mysqli_sql_exception $e) {
    error_log('guardar_proveedor bitácora: ' . $e->getMessage());
}
volverProveedor('proveedor_guardado');
?>
