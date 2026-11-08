<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf_utils.php';
require_once __DIR__ . '/dispositivos_confianza_utils.php';
nuviRequerirCsrf();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Acceso denegado.']));
}
$datos = json_decode(file_get_contents('php://input'), true);
$id = filter_var($datos['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Usuario inválido.']));
}
require_once __DIR__ . '/conexion.php';
try {
    if (!$conexion instanceof mysqli) throw new RuntimeException('Base no disponible');
    if (!$conexion->begin_transaction()) throw new RuntimeException('No se pudo iniciar la operación');
    $bloqueo = nuviConfianzaPreparar($conexion, 'SELECT id FROM usuarios WHERE id = ? FOR UPDATE');
    $bloqueo->bind_param('i', $id);
    nuviConfianzaEjecutar($bloqueo);
    if (!$bloqueo->get_result()->fetch_assoc()) throw new RuntimeException('Usuario inexistente');
    $bloqueo->close();
    $stmt = nuviConfianzaPreparar($conexion, 'DELETE FROM dispositivos_confianza WHERE usuario_id = ?');
    $stmt->bind_param('i', $id);
    nuviConfianzaEjecutar($stmt);
    $stmt->close();
    if (!$conexion->commit()) throw new RuntimeException('No se pudo confirmar la operación');
    echo json_encode(['success' => true, 'message' => 'Accesos rápidos revocados. Las sesiones abiertas no se cerraron.']);
} catch (Throwable $e) {
    if ($conexion instanceof mysqli) $conexion->rollback();
    error_log('Revocar dispositivos: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'No se pudieron revocar los accesos.']);
}
