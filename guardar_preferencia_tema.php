<?php
header('Content-Type: application/json; charset=utf-8');
require_once('auth.php');
require_once('conexion.php');

function responderTema(int $codigo, array $respuesta): void
{
    http_response_code($codigo);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    responderTema(503, ['success' => false, 'message' => 'Sin conexión a la base de datos']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
$tema = is_array($entrada) ? ($entrada['tema'] ?? '') : '';
if (!in_array($tema, ['claro', 'oscuro'], true)) {
    responderTema(422, ['success' => false, 'message' => 'Tema inválido']);
}

$idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
if ($idUsuario <= 0) {
    responderTema(401, ['success' => false, 'message' => 'Sesión no válida']);
}

try {
    $columna = mysqli_query($conexion, "SHOW COLUMNS FROM usuarios LIKE 'tema_ui'");
    if (!$columna || mysqli_num_rows($columna) === 0) {
        throw new Exception('Falta ejecutar la migración v1.2 para guardar esta preferencia');
    }

    $stmt = mysqli_prepare($conexion, 'UPDATE usuarios SET tema_ui = ? WHERE id = ?');
    if (!$stmt) {
        throw new Exception('No se pudo guardar la preferencia');
    }
    mysqli_stmt_bind_param($stmt, 'si', $tema, $idUsuario);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('No se pudo guardar la preferencia');
    }

    $_SESSION['tema_ui'] = $tema;
    responderTema(200, ['success' => true, 'tema' => $tema]);
} catch (Throwable $error) {
    error_log('guardar_preferencia_tema: ' . $error->getMessage());
    responderTema(500, ['success' => false, 'message' => 'No se pudo guardar la preferencia visual']);
}
