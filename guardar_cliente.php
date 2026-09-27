<?php
header('Content-Type: application/json');
require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once("conexion.php"); // Corregido: Usamos tu archivo real de conexión
require_once('auditoria_utils.php');

// Recibir los datos de Javascript
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['nombre'])) {
    echo json_encode(["success" => false, "message" => "Faltan datos"]);
    exit;
}

// Preparar variables
$nombre = trim((string) $data['nombre']);
$telefono = trim((string) ($data['telefono'] ?? ''));
$limiteRecibido = trim((string) ($data['limite'] ?? ''));
$limite = $limiteRecibido === '' ? 500.00 : (is_numeric($limiteRecibido) ? round((float) $limiteRecibido, 2) : -1);
$longitudNombre = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
$longitudTelefono = function_exists('mb_strlen') ? mb_strlen($telefono) : strlen($telefono);
if ($nombre === '' || $limite < 0 || $limite > 999999.99 || $longitudNombre > 100 || $longitudTelefono > 15 || preg_match('/[\x00-\x1F\x7F]/', $telefono)) {
    echo json_encode(["success" => false, "message" => "Datos inválidos"]);
    exit;
}

// Usamos mysqli, que es lo que maneja tu base de datos en Nuvi
$sql = "INSERT INTO clientes (nombre_completo, telefono, limite_credito) VALUES (?, ?, ?)";
$stmt = mysqli_prepare($conexion, $sql);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "ssd", $nombre, $telefono, $limite);

    if (mysqli_stmt_execute($stmt)) {
        $idCliente = (int) mysqli_insert_id($conexion);
        nuviRegistrarAuditoria(
            $conexion,
            'cliente_creado',
            'cliente',
            $idCliente,
            'Cliente registrado: ' . $nombre,
            ['limite_credito' => $limite]
        );
        echo json_encode(["success" => true]);
    } else {
        error_log('guardar_cliente.php: ' . mysqli_stmt_error($stmt));
        echo json_encode(["success" => false, "message" => "No se pudo guardar el cliente"]);
    }
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(["success" => false, "message" => "Error de BD"]);
}
?>
