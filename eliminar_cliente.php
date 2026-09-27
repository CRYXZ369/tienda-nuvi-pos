<?php
header('Content-Type: application/json; charset=utf-8');
require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once('conexion.php');
require_once('auditoria_utils.php');

if (!in_array($_SESSION['rol'] ?? '', ['admin', 'supervisor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para archivar clientes.']);
    exit;
}
$datos = json_decode(file_get_contents('php://input'), true);
$idCliente = intval($datos['id_cliente'] ?? 0);
if ($idCliente <= 0) {
    echo json_encode(['success' => false, 'message' => 'Cliente inválido']);
    exit;
}

$colActivo = mysqli_query($conexion, "SHOW COLUMNS FROM clientes LIKE 'activo'");
if (!$colActivo || mysqli_num_rows($colActivo) === 0) {
    echo json_encode(['success' => false, 'message' => 'Primero ejecuta la migración v1.2; así se preservará el historial.']);
    exit;
}

$consulta = mysqli_prepare($conexion, 'SELECT nombre_completo FROM clientes WHERE id_cliente = ? LIMIT 1');
mysqli_stmt_bind_param($consulta, 'i', $idCliente);
mysqli_stmt_execute($consulta);
$cliente = mysqli_fetch_assoc(mysqli_stmt_get_result($consulta));
if (!$cliente) {
    echo json_encode(['success' => false, 'message' => 'Cliente no encontrado.']);
    exit;
}

$stmt = mysqli_prepare($conexion, 'UPDATE clientes SET activo = 0 WHERE id_cliente = ?');
mysqli_stmt_bind_param($stmt, 'i', $idCliente);
if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
    echo json_encode(['success' => false, 'message' => 'No se encontró el cliente o ya estaba archivado.']);
    exit;
}
nuviRegistrarAuditoria(
    $conexion,
    'cliente_archivado',
    'cliente',
    $idCliente,
    'Cliente archivado: ' . (string) $cliente['nombre_completo']
);
echo json_encode(['success' => true, 'message' => 'Cliente archivado. Su libreta e historial se conservaron.']);
?>
