<?php
header('Content-Type: application/json; charset=utf-8');

require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once('conexion.php');
require_once('auditoria_utils.php');

function responderClienteActualizado(int $codigo, array $payload): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!in_array($_SESSION['rol'] ?? '', ['admin', 'supervisor'], true)) {
    responderClienteActualizado(403, [
        'success' => false,
        'message' => 'Sólo administración puede editar datos y límite de crédito de clientes.'
    ]);
}

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    responderClienteActualizado(500, ['success' => false, 'message' => 'Sin conexión a base de datos.']);
}

$datos = json_decode(file_get_contents('php://input'), true);
if (!is_array($datos)) {
    responderClienteActualizado(400, ['success' => false, 'message' => 'Datos inválidos.']);
}

$idCliente = (int) ($datos['id_cliente'] ?? 0);
$nombre = trim((string) ($datos['nombre'] ?? ''));
$telefono = trim((string) ($datos['telefono'] ?? ''));
$limiteRaw = $datos['limite'] ?? null;

if ($idCliente <= 0 || $nombre === '' || $limiteRaw === null || $limiteRaw === '' || !is_numeric($limiteRaw)) {
    responderClienteActualizado(400, ['success' => false, 'message' => 'Completa nombre y límite de crédito con datos válidos.']);
}

$longitudNombre = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
$longitudTelefono = function_exists('mb_strlen') ? mb_strlen($telefono) : strlen($telefono);
$limite = round((float) $limiteRaw, 2);

if ($longitudNombre > 100 || $longitudTelefono > 15 || preg_match('/[\x00-\x1F\x7F]/', $telefono) || $limite < 0 || $limite > 999999.99) {
    responderClienteActualizado(400, ['success' => false, 'message' => 'Revisa el nombre, teléfono y límite de crédito.']);
}

$columnas = [];
$resultadoColumnas = mysqli_query($conexion, 'SHOW COLUMNS FROM clientes');
if ($resultadoColumnas) {
    while ($columna = mysqli_fetch_assoc($resultadoColumnas)) {
        $columnas[] = (string) ($columna['Field'] ?? '');
    }
}

foreach (['id_cliente', 'nombre_completo', 'telefono', 'limite_credito'] as $columnaRequerida) {
    if (!in_array($columnaRequerida, $columnas, true)) {
        responderClienteActualizado(500, ['success' => false, 'message' => 'La libreta requiere actualización antes de editar clientes.']);
    }
}

try {
    $consulta = mysqli_prepare(
        $conexion,
        'SELECT nombre_completo, telefono, limite_credito FROM clientes WHERE id_cliente = ? LIMIT 1'
    );
    if (!$consulta) {
        throw new RuntimeException('No se pudo consultar el cliente.');
    }
    mysqli_stmt_bind_param($consulta, 'i', $idCliente);
    mysqli_stmt_execute($consulta);
    $clienteAnterior = mysqli_fetch_assoc(mysqli_stmt_get_result($consulta));
    mysqli_stmt_close($consulta);

    if (!$clienteAnterior) {
        responderClienteActualizado(404, ['success' => false, 'message' => 'Cliente no encontrado.']);
    }

    $nombreAnterior = trim((string) ($clienteAnterior['nombre_completo'] ?? ''));
    $telefonoAnterior = trim((string) ($clienteAnterior['telefono'] ?? ''));
    $limiteAnterior = round((float) ($clienteAnterior['limite_credito'] ?? 0), 2);
    $cambios = [];
    if ($nombre !== $nombreAnterior) $cambios[] = 'nombre';
    if ($telefono !== $telefonoAnterior) $cambios[] = 'teléfono';
    if (abs($limite - $limiteAnterior) > 0.00001) $cambios[] = 'límite de crédito';

    if (!$cambios) {
        responderClienteActualizado(200, [
            'success' => true,
            'message' => 'No había cambios por guardar.',
            'cliente' => [
                'id_cliente' => $idCliente,
                'nombre_completo' => $nombreAnterior,
                'telefono' => $telefonoAnterior,
                'limite_credito' => $limiteAnterior,
            ],
        ]);
    }

    $actualizar = mysqli_prepare(
        $conexion,
        'UPDATE clientes SET nombre_completo = ?, telefono = ?, limite_credito = ? WHERE id_cliente = ?'
    );
    if (!$actualizar) {
        throw new RuntimeException('No se pudo preparar la actualización del cliente.');
    }
    mysqli_stmt_bind_param($actualizar, 'ssdi', $nombre, $telefono, $limite, $idCliente);
    if (!mysqli_stmt_execute($actualizar)) {
        throw new RuntimeException('No se pudo guardar el cliente.');
    }
    mysqli_stmt_close($actualizar);

    nuviRegistrarAuditoria(
        $conexion,
        'cliente_actualizado',
        'cliente',
        $idCliente,
        'Cliente actualizado: ' . $nombre,
        [
            'campos_actualizados' => $cambios,
            'limite_anterior' => $limiteAnterior,
            'limite_nuevo' => $limite,
        ]
    );

    responderClienteActualizado(200, [
        'success' => true,
        'message' => 'Cliente actualizado correctamente.',
        'cliente' => [
            'id_cliente' => $idCliente,
            'nombre_completo' => $nombre,
            'telefono' => $telefono,
            'limite_credito' => $limite,
        ],
    ]);
} catch (Throwable $error) {
    error_log('actualizar_cliente.php: ' . $error->getMessage());
    responderClienteActualizado(500, ['success' => false, 'message' => 'No se pudo actualizar el cliente.']);
}
?>
