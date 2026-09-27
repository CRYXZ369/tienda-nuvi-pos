<?php
header('Content-Type: application/json; charset=utf-8');

require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once('conexion.php');
require_once('auditoria_utils.php');

function responderClienteRestaurado(int $codigo, array $payload): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!in_array($_SESSION['rol'] ?? '', ['admin', 'supervisor'], true)) {
    responderClienteRestaurado(403, [
        'success' => false,
        'message' => 'No tienes permiso para desarchivar clientes.'
    ]);
}

$datos = json_decode(file_get_contents('php://input'), true);
$idCliente = (int) ($datos['id_cliente'] ?? 0);
if ($idCliente <= 0) {
    responderClienteRestaurado(400, ['success' => false, 'message' => 'Cliente inválido.']);
}

$columnaActivo = mysqli_query($conexion, "SHOW COLUMNS FROM clientes LIKE 'activo'");
if (!$columnaActivo || mysqli_num_rows($columnaActivo) === 0) {
    responderClienteRestaurado(409, [
        'success' => false,
        'message' => 'Primero ejecuta la migración v1.2 para restaurar clientes sin perder historial.'
    ]);
}

try {
    $consulta = mysqli_prepare($conexion, 'SELECT nombre_completo, activo FROM clientes WHERE id_cliente = ? LIMIT 1');
    if (!$consulta) {
        throw new RuntimeException('No se pudo consultar el cliente.');
    }
    mysqli_stmt_bind_param($consulta, 'i', $idCliente);
    mysqli_stmt_execute($consulta);
    $cliente = mysqli_fetch_assoc(mysqli_stmt_get_result($consulta));
    mysqli_stmt_close($consulta);

    if (!$cliente) {
        responderClienteRestaurado(404, ['success' => false, 'message' => 'Cliente no encontrado.']);
    }
    if ((int) ($cliente['activo'] ?? 1) === 1) {
        responderClienteRestaurado(200, ['success' => true, 'message' => 'El cliente ya estaba activo.']);
    }

    $actualizar = mysqli_prepare($conexion, 'UPDATE clientes SET activo = 1 WHERE id_cliente = ? AND activo = 0');
    if (!$actualizar) {
        throw new RuntimeException('No se pudo preparar la restauración del cliente.');
    }
    mysqli_stmt_bind_param($actualizar, 'i', $idCliente);
    if (!mysqli_stmt_execute($actualizar) || mysqli_stmt_affected_rows($actualizar) < 1) {
        throw new RuntimeException('No se pudo restaurar el cliente.');
    }
    mysqli_stmt_close($actualizar);

    $nombre = trim((string) ($cliente['nombre_completo'] ?? 'Cliente'));
    nuviRegistrarAuditoria(
        $conexion,
        'cliente_desarchivado',
        'cliente',
        $idCliente,
        'Cliente desarchivado: ' . $nombre
    );
    responderClienteRestaurado(200, ['success' => true, 'message' => 'Cliente restaurado a la libreta activa.']);
} catch (Throwable $error) {
    error_log('restaurar_cliente.php: ' . $error->getMessage());
    responderClienteRestaurado(500, ['success' => false, 'message' => 'No se pudo restaurar el cliente.']);
}
?>
