<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once('auth.php');
tienePermiso(['admin']);
require_once('conexion.php');
require_once('auditoria_utils.php');
require_once('impresion_ticket_utils.php');

try {
    if (!$conexion instanceof mysqli) {
        throw new RuntimeException('No hay conexión con la base de datos.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new InvalidArgumentException('Método no permitido.');
    }
    $datos = json_decode(file_get_contents('php://input'), true);
    if (!is_array($datos)) {
        throw new InvalidArgumentException('Datos de impresora inválidos.');
    }
    $config = nuviGuardarConfigImpresora($conexion, $datos, (int) ($_SESSION['id_usuario'] ?? 0));
    nuviRegistrarAuditoria(
        $conexion,
        'configuracion_impresora',
        'impresora_ticket',
        null,
        $config['activa'] ? 'Impresora de tickets configurada.' : 'Impresora de tickets desactivada.',
        ['host' => $config['host'], 'puerto' => $config['puerto'], 'nombre' => $config['nombre'], 'activa' => $config['activa']]
    );
    echo json_encode(['success' => true, 'config' => $config], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
