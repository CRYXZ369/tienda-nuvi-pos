<?php
if (PHP_SAPI !== 'cli' || !str_ends_with((string) getenv('NUVI_DB_NAME'), '_test')) {
    throw new RuntimeException('Se requiere una base de pruebas terminada en _test.');
}
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../dispositivos_confianza_utils.php';
if (!$conexion instanceof mysqli || $conexion->query('SELECT DATABASE()')->fetch_row()[0] !== getenv('NUVI_DB_NAME')) {
    throw new RuntimeException('Base de pruebas no disponible.');
}
function comprobarConfianza(bool $condicion, string $mensaje): void
{
    if (!$condicion) throw new RuntimeException($mensaje);
}

$conexion->begin_transaction();
try {
    $nombre = 'Prueba confianza';
    $usuario = 'confianza_' . bin2hex(random_bytes(6));
    $password = password_hash('prueba ficticia', PASSWORD_DEFAULT);
    $stmt = $conexion->prepare("INSERT INTO usuarios (nombre, apellidos, usuario, password, rol, estado) VALUES (?, 'Prueba', ?, ?, 'cajero', 1)");
    $stmt->bind_param('sss', $nombre, $usuario, $password);
    $stmt->execute();
    $id = (int) $conexion->insert_id;
    $selector = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $passwordHash = hash('sha256', $password);
    $stmt = $conexion->prepare('INSERT INTO dispositivos_confianza (selector, usuario_id, token_hash, password_hash, vence) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))');
    $stmt->bind_param('siss', $selector, $id, $tokenHash, $passwordHash);
    $stmt->execute();
    $_COOKIE = [NUVI_CONFIANZA_PREFIJO . $selector => $token];

    $perfiles = nuviConfianzaPerfiles($conexion);
    comprobarConfianza(isset($perfiles[$selector]) && (int) $perfiles[$selector]['id'] === $id, 'El perfil válido debe mostrarse.');
    $_COOKIE[NUVI_CONFIANZA_PREFIJO . $selector] = str_repeat('0', 64);
    comprobarConfianza(!nuviConfianzaPerfiles($conexion), 'Un token incorrecto no debe autenticar.');
    $_COOKIE[NUVI_CONFIANZA_PREFIJO . $selector] = $token;

    $conexion->query("UPDATE usuarios SET rol = 'admin' WHERE id = $id");
    comprobarConfianza(nuviConfianzaPerfiles($conexion)[$selector]['rol'] === 'admin', 'Debe respetar el rol actual.');
    $conexion->query("UPDATE usuarios SET estado = 0 WHERE id = $id");
    comprobarConfianza(!nuviConfianzaPerfiles($conexion), 'Una cuenta desactivada no debe autenticar.');
    $conexion->query("UPDATE usuarios SET estado = 1, password = 'hash-distinto' WHERE id = $id");
    comprobarConfianza(!nuviConfianzaPerfiles($conexion), 'Un cambio de contraseña debe invalidar el perfil.');
    $stmt = $conexion->prepare('UPDATE usuarios SET password = ? WHERE id = ?');
    $stmt->bind_param('si', $password, $id);
    $stmt->execute();
    $conexion->query("UPDATE dispositivos_confianza SET vence = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE usuario_id = $id");
    comprobarConfianza(!nuviConfianzaPerfiles($conexion), 'Un perfil vencido no debe autenticar.');
    $conexion->rollback();
    echo "OK: token, rol, estado, contraseña y vencimiento de perfiles de confianza.\n";
} catch (Throwable $e) {
    $conexion->rollback();
    throw $e;
}
