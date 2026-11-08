<?php
const NUVI_CONFIANZA_PREFIJO = 'nuvi_confianza_';
const NUVI_CONFIANZA_DIAS = 30;

function nuviConfianzaPreparar(mysqli $conexion, string $sql): mysqli_stmt
{
    $stmt = $conexion->prepare($sql);
    if (!$stmt) throw new RuntimeException('No se pudo preparar la consulta de dispositivos.');
    return $stmt;
}

function nuviConfianzaEjecutar(mysqli_stmt $stmt): void
{
    if (!$stmt->execute()) throw new RuntimeException('No se pudo ejecutar la consulta de dispositivos.');
}

function nuviConfianzaHttps(): bool
{
    return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

function nuviConfianzaCookie(string $selector, string $valor, int $vence): void
{
    $ruta = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    setcookie(NUVI_CONFIANZA_PREFIJO . $selector, $valor, [
        'expires' => $vence, 'path' => $ruta, 'secure' => true,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function nuviConfianzaCookies(): array
{
    $tokens = [];
    foreach ($_COOKIE as $nombre => $valor) {
        if (preg_match('/^nuvi_confianza_([a-f0-9]{32})$/D', $nombre, $m)
            && is_string($valor) && preg_match('/^[a-f0-9]{64}$/D', $valor)) {
            $tokens[$m[1]] = $valor;
            if (count($tokens) >= 12) break;
        }
    }
    return $tokens;
}

function nuviConfianzaPerfiles(mysqli $conexion): array
{
    $perfiles = [];
    $stmt = nuviConfianzaPreparar($conexion, 'SELECT d.token_hash, d.password_hash, u.id, u.nombre, u.rol, u.tema_ui, u.password
        FROM dispositivos_confianza d JOIN usuarios u ON u.id = d.usuario_id
        WHERE d.selector = ? AND d.vence > NOW() AND u.estado = 1 LIMIT 1');
    foreach (nuviConfianzaCookies() as $selector => $token) {
        $stmt->bind_param('s', $selector);
        nuviConfianzaEjecutar($stmt);
        $fila = $stmt->get_result()->fetch_assoc();
        if ($fila && hash_equals($fila['token_hash'], hash('sha256', $token))
            && hash_equals($fila['password_hash'], hash('sha256', $fila['password']))) {
            unset($fila['token_hash'], $fila['password_hash'], $fila['password']);
            $perfiles[$selector] = $fila;
        }
    }
    $stmt->close();
    return $perfiles;
}

function nuviConfianzaCrear(mysqli $conexion, array $usuario): void
{
    if (!nuviConfianzaHttps()) throw new RuntimeException('El acceso de confianza requiere HTTPS.');
    $tokens = nuviConfianzaCookies();
    if (count($tokens) >= 12) throw new RuntimeException('Este dispositivo ya tiene el máximo de perfiles guardados.');
    $selector = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $passHash = hash('sha256', $usuario['password']);
    $id = (int) $usuario['id'];
    if (!$conexion->begin_transaction()) throw new RuntimeException('No se pudo iniciar la operación de confianza.');
    try {
        $actual = nuviConfianzaPreparar($conexion, 'SELECT password, estado FROM usuarios WHERE id = ? FOR UPDATE');
        $actual->bind_param('i', $id);
        nuviConfianzaEjecutar($actual);
        $fila = $actual->get_result()->fetch_assoc();
        $actual->close();
        if (!$fila || (int) $fila['estado'] !== 1 || !hash_equals($passHash, hash('sha256', $fila['password']))) {
            throw new RuntimeException('La cuenta cambió; vuelve a iniciar sesión.');
        }
        $stmt = nuviConfianzaPreparar($conexion, 'INSERT INTO dispositivos_confianza (selector, usuario_id, token_hash, password_hash, vence) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))');
        $stmt->bind_param('siss', $selector, $id, $tokenHash, $passHash);
        nuviConfianzaEjecutar($stmt);
        $stmt->close();
        if (!$conexion->commit()) throw new RuntimeException('No se pudo confirmar la operación de confianza.');
    } catch (Throwable $e) {
        $conexion->rollback();
        throw $e;
    }
    nuviConfianzaCookie($selector, $token, time() + NUVI_CONFIANZA_DIAS * 86400);
}

function nuviConfianzaEliminar(mysqli $conexion, string $selector): void
{
    $tokens = nuviConfianzaCookies();
    if (!isset($tokens[$selector])) throw new RuntimeException('Perfil no disponible en este dispositivo.');
    $hash = hash('sha256', $tokens[$selector]);
    $stmt = nuviConfianzaPreparar($conexion, 'DELETE FROM dispositivos_confianza WHERE selector = ? AND token_hash = ?');
    $stmt->bind_param('ss', $selector, $hash);
    nuviConfianzaEjecutar($stmt);
    $stmt->close();
    nuviConfianzaCookie($selector, '', time() - 3600);
}

function nuviConfianzaIniciarSesion(array $usuario): void
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['id_usuario'] = $usuario['id'];
    $_SESSION['nombre'] = $usuario['nombre'];
    $_SESSION['rol'] = $usuario['rol'];
    $_SESSION['tema_ui'] = ($usuario['tema_ui'] ?? 'claro') === 'oscuro' ? 'oscuro' : 'claro';
}
