<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/conexion.php';
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    fwrite(STDERR, "No hay conexión con la base configurada.\n");
    exit(1);
}

$nombre = trim((string) getenv('NUVI_ADMIN_NOMBRE'));
$apellidos = trim((string) getenv('NUVI_ADMIN_APELLIDOS'));
$usuario = trim((string) getenv('NUVI_ADMIN_USUARIO'));
$password = (string) getenv('NUVI_ADMIN_PASSWORD');
$largoNombre = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
$largoApellidos = function_exists('mb_strlen') ? mb_strlen($apellidos) : strlen($apellidos);

if ($nombre === '' || $apellidos === '' || $largoNombre > 50 || $largoApellidos > 50
    || strlen($usuario) < 3 || strlen($usuario) > 30 || !preg_match('/^[A-Za-z0-9._-]+$/', $usuario)
    || strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR, "Datos inválidos: nombres de hasta 50 caracteres, usuario de 3-30 caracteres y contraseña de 12-72 bytes.\n");
    exit(1);
}

$conteo = mysqli_query($conexion, 'SELECT COUNT(*) AS total FROM usuarios');
if (!$conteo || (int) (mysqli_fetch_assoc($conteo)['total'] ?? 0) !== 0) {
    fwrite(STDERR, "La tabla usuarios no está vacía; no se creó otro administrador.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = mysqli_prepare($conexion, 'INSERT INTO usuarios (nombre, apellidos, usuario, password, rol, estado) VALUES (?, ?, ?, ?, ?, 1)');
$rol = 'admin';
if (!$stmt) {
    fwrite(STDERR, "No se pudo preparar la creación del administrador.\n");
    exit(1);
}

mysqli_stmt_bind_param($stmt, 'sssss', $nombre, $apellidos, $usuario, $hash, $rol);
if (!mysqli_stmt_execute($stmt)) {
    fwrite(STDERR, 'No se pudo crear el administrador: ' . mysqli_stmt_error($stmt) . "\n");
    exit(1);
}

mysqli_stmt_close($stmt);
fwrite(STDOUT, "Administrador inicial creado.\n");