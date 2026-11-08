<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf_utils.php';
nuviRequerirCsrf();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respuestaRegistroError(int $codigo, string $mensaje): void
{
    http_response_code($codigo);
    echo json_encode(['success' => $codigo === 200, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respuestaRegistroError(405, 'Método no permitido.');
}
$entrada = file_get_contents('php://input', false, null, 0, 8193);
if ($entrada === false || strlen($entrada) > 8192) {
    respuestaRegistroError(413, 'Reporte demasiado grande.');
}
$datos = json_decode($entrada, true);
if (!is_array($datos)
    || !isset($datos['id'], $datos['tipo'], $datos['motivo'], $datos['pagina'], $datos['origen'])
    || !is_string($datos['id']) || !preg_match('/^[a-f0-9]{32}$/', $datos['id'])
    || !in_array($datos['tipo'], ['error', 'comentario'], true)
    || !in_array($datos['motivo'], ['http', 'respuesta', 'conexion', 'javascript', 'recurso', 'promesa'], true)
    || !is_string($datos['pagina']) || !preg_match('/^[a-zA-Z0-9_.\/-]{1,160}$/', $datos['pagina'])
    || !is_string($datos['origen']) || !preg_match('/^[a-zA-Z0-9_.\/-]{1,160}$/', $datos['origen'])) {
    respuestaRegistroError(422, 'Reporte inválido.');
}
$comentario = $datos['comentario'] ?? '';
if (!is_string($comentario) || mb_strlen($comentario) > 500
    || ($datos['tipo'] === 'comentario' && trim($comentario) === '')) {
    respuestaRegistroError(422, 'Comentario inválido.');
}
$usuario = (int) $_SESSION['id_usuario'];
if ((int) ($datos['usuario'] ?? 0) !== $usuario) {
    respuestaRegistroError(409, 'El reporte pertenece a otra cuenta.');
}
$ahora = time();
$_SESSION['nuvi_reportes_error'] = array_values(array_filter(
    $_SESSION['nuvi_reportes_error'] ?? [],
    static function ($fecha) use ($ahora) { return is_int($fecha) && $fecha > $ahora - 60; }
));
if (count($_SESSION['nuvi_reportes_error']) >= 60) {
    respuestaRegistroError(429, 'Demasiados reportes; se intentará más tarde.');
}
$_SESSION['nuvi_reportes_error'][] = $ahora;
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auditoria_utils.php';
if (!$conexion instanceof mysqli || !nuviAuditoriaDisponible($conexion)) {
    error_log('nuvi diagnóstico: bitácora no disponible');
    respuestaRegistroError(503, 'La bitácora no está disponible.');
}

$accion = $datos['tipo'] === 'comentario' ? 'comentario_error' : 'error_navegador';
$detalles = [
    'referencia' => $datos['id'],
    'motivo' => $datos['motivo'],
    'pagina' => $datos['pagina'],
    'origen' => $datos['origen'],
    'http' => max(0, min(599, (int) ($datos['http'] ?? 0))),
    'linea' => max(0, min(1000000, (int) ($datos['linea'] ?? 0))),
    'columna' => max(0, min(1000000, (int) ($datos['columna'] ?? 0))),
    'fecha_dispositivo' => substr((string) ($datos['fecha'] ?? ''), 0, 30),
    'navegador' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
];
if (in_array($datos['codigo'] ?? '', [
    'CSRF_INVALID', 'SESSION_EXPIRED', 'PRECIO_INVALIDO', 'STOCK_INVALIDO',
    'PRODUCTO_NO_ENCONTRADO', 'CAMPO_INVALIDO', 'CODIGO_REPETIDO',
    'PRECIO_CAMBIO', 'STOCK_CAMBIO', 'VENTA_ERROR_INTERNO'
], true)) {
    $detalles['codigo'] = $datos['codigo'];
}
if (($datos['origen'] ?? '') === 'actualizar_inventario.php') {
    $detalles['producto_id'] = max(0, (int) ($datos['producto_id'] ?? 0));
    if (in_array($datos['campo'] ?? '', ['nombre', 'marca', 'precio_venta', 'precio_compra', 'stock', 'categoria', 'codigo_barras', 'activo'], true)) {
        $detalles['campo'] = $datos['campo'];
    }
}
if ($accion === 'comentario_error') {
    $detalles['comentario'] = trim($comentario);
}
$json = json_encode($detalles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$descripcion = $accion === 'comentario_error' ? 'Comentario sobre un error' : 'Error detectado en el dispositivo';
$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

try {
    // El ID permite reintentar después de una respuesta perdida sin duplicar.
    $buscar = mysqli_prepare($conexion, "SELECT id FROM bitacora_auditoria WHERE id_usuario = ? AND accion = ? AND detalles_json LIKE ? LIMIT 1");
    $patron = '%"referencia":"' . $datos['id'] . '"%';
    mysqli_stmt_bind_param($buscar, 'iss', $usuario, $accion, $patron);
    if (!mysqli_stmt_execute($buscar)) {
        throw new RuntimeException('No se pudo consultar la bitácora.');
    }
    $existe = mysqli_fetch_assoc(mysqli_stmt_get_result($buscar));
    mysqli_stmt_close($buscar);
    if (!$existe) {
        $stmt = mysqli_prepare($conexion, "INSERT INTO bitacora_auditoria (id_usuario, accion, entidad, descripcion, detalles_json, ip_origen) VALUES (?, ?, 'diagnostico', ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'issss', $usuario, $accion, $descripcion, $json, $ip);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('No se pudo insertar en la bitácora.');
        }
        mysqli_stmt_close($stmt);
    }
} catch (Throwable $e) {
    error_log('nuvi diagnóstico: no se pudo guardar en bitácora (' . get_class($e) . ')');
    respuestaRegistroError(503, 'No se pudo guardar el reporte.');
}
respuestaRegistroError(200, 'Reporte guardado.');
