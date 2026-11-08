<?php
header('Content-Type: application/json; charset=utf-8');
include("../auth.php");
tienePermiso(['admin']);
include("../conexion.php");
require_once('../auditoria_utils.php');

$raw = file_get_contents('php://input');
$data = $raw ? json_decode($raw, true) : null;
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$accion = isset($data['accion']) ? $data['accion'] : null;
$transaccion = false;

try {
    $auditoriaAccion = 'usuario_actualizado';
    $auditoriaEntidadId = 0;
    $auditoriaDescripcion = 'Usuario actualizado';
    $auditoriaDetalles = [];
    if ($accion === 'cambiar_rol') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        $nuevoRol = isset($data['rol']) ? $data['rol'] : null;
        if ($id <= 0 || !in_array($nuevoRol, ['admin', 'cajero'], true)) throw new Exception('Rol inválido');
        $rolActualStmt = mysqli_prepare($conexion, 'SELECT rol, nombre FROM usuarios WHERE id = ? AND estado = 1 LIMIT 1');
        mysqli_stmt_bind_param($rolActualStmt, 'i', $id);
        mysqli_stmt_execute($rolActualStmt);
        $usuarioActual = mysqli_fetch_assoc(mysqli_stmt_get_result($rolActualStmt));
        if (!$usuarioActual) throw new Exception('El usuario no existe o está desactivado');
        if ($usuarioActual['rol'] === 'admin' && $nuevoRol !== 'admin') {
            $admins = mysqli_query($conexion, "SELECT COUNT(*) AS total FROM usuarios WHERE rol = 'admin' AND estado = 1");
            $totalAdmins = (int) (mysqli_fetch_assoc($admins)['total'] ?? 0);
            if ($totalAdmins <= 1) throw new Exception('Debe permanecer al menos un administrador activo');
        }
        $stmt = mysqli_prepare($conexion, "UPDATE usuarios SET rol = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $nuevoRol, $id);
        $auditoriaEntidadId = $id;
        $auditoriaDescripcion = 'Rol actualizado para ' . (string) $usuarioActual['nombre'];
        $auditoriaDetalles = ['rol_anterior' => $usuarioActual['rol'], 'rol_nuevo' => $nuevoRol];
    } elseif ($accion === 'cambiar_pass') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        $passRaw = isset($data['password']) ? $data['password'] : null;
        if ($id <= 0 || strlen($passRaw) < 4) throw new Exception('La contraseña debe tener al menos 4 caracteres');
        $passHash = password_hash($passRaw, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conexion, "UPDATE usuarios SET password = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $passHash, $id);
        $auditoriaEntidadId = $id;
        $auditoriaDescripcion = 'Contraseña de usuario restablecida';
        $auditoriaDetalles = ['campo' => 'contraseña'];
    } elseif ($accion === 'editar_nombre') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        $nombre = isset($data['nombre']) ? trim($data['nombre']) : '';
        if ($id <= 0 || $nombre === '') throw new Exception('Parámetros inválidos');
        $stmt = mysqli_prepare($conexion, "UPDATE usuarios SET nombre = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $nombre, $id);
        $auditoriaEntidadId = $id;
        $auditoriaDescripcion = 'Nombre de usuario actualizado';
        $auditoriaDetalles = ['nombre_nuevo' => $nombre];
    } elseif ($accion === 'agregar_usuario') {
        $nombre = isset($data['nombre']) ? trim($data['nombre']) : '';
        $usuario = isset($data['usuario']) ? trim($data['usuario']) : '';
        $password = isset($data['password']) ? trim($data['password']) : '';
        $rol = isset($data['rol']) ? trim($data['rol']) : 'cajero';
        if ($nombre === '' || $usuario === '' || $password === '') throw new Exception('Completa todos los campos');
        if (strlen($password) < 4) throw new Exception('La contraseña debe tener al menos 4 caracteres');
        if (!in_array($rol, ['admin', 'cajero'], true)) throw new Exception('Rol inválido');

        $check = mysqli_prepare($conexion, "SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
        mysqli_stmt_bind_param($check, "s", $usuario);
        mysqli_stmt_execute($check);
        $exists = mysqli_stmt_get_result($check);
        if (mysqli_fetch_assoc($exists)) {
            mysqli_stmt_close($check);
            throw new Exception('El usuario ya existe');
        }
        mysqli_stmt_close($check);

        $passHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conexion, "INSERT INTO usuarios (nombre, usuario, password, rol, estado) VALUES (?, ?, ?, ?, 1)");
        mysqli_stmt_bind_param($stmt, "ssss", $nombre, $usuario, $passHash, $rol);
        $auditoriaAccion = 'usuario_creado';
        $auditoriaDescripcion = 'Usuario creado: ' . $nombre;
        $auditoriaDetalles = ['usuario' => $usuario, 'rol' => $rol];
    } elseif ($accion === 'eliminar_usuario') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        if ($id <= 0) throw new Exception('Parámetros inválidos');
        if ($id === (int) ($_SESSION['id_usuario'] ?? 0)) throw new Exception('No puedes desactivar tu propio usuario');

        $rolObjetivo = mysqli_prepare($conexion, 'SELECT rol, nombre FROM usuarios WHERE id = ? AND estado = 1 LIMIT 1');
        mysqli_stmt_bind_param($rolObjetivo, 'i', $id);
        mysqli_stmt_execute($rolObjetivo);
        $usuarioObjetivo = mysqli_fetch_assoc(mysqli_stmt_get_result($rolObjetivo));
        if (!$usuarioObjetivo) throw new Exception('El usuario no existe o ya está desactivado');
        if ($usuarioObjetivo['rol'] === 'admin') {
            $admins = mysqli_query($conexion, "SELECT COUNT(*) AS total FROM usuarios WHERE rol = 'admin' AND estado = 1");
            $totalAdmins = (int) (mysqli_fetch_assoc($admins)['total'] ?? 0);
            if ($totalAdmins <= 1) throw new Exception('Debe permanecer al menos un administrador activo');
        }
        $stmt = mysqli_prepare($conexion, "UPDATE usuarios SET estado = 0 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        $auditoriaAccion = 'usuario_desactivado';
        $auditoriaEntidadId = $id;
        $auditoriaDescripcion = 'Usuario desactivado: ' . (string) $usuarioObjetivo['nombre'];
    } else {
        throw new Exception('Acción no soportada');
    }

    if (!$stmt) throw new Exception('Error interno: declaración no preparada');

    if (in_array($accion, ['cambiar_pass', 'eliminar_usuario'], true)) {
        if (!mysqli_begin_transaction($conexion)) throw new Exception('No se pudo iniciar la operación.');
        $transaccion = true;
        $bloqueo = mysqli_prepare($conexion, 'SELECT id FROM usuarios WHERE id = ? FOR UPDATE');
        if (!$bloqueo) throw new Exception('No se pudo verificar el usuario.');
        mysqli_stmt_bind_param($bloqueo, 'i', $id);
        if (!mysqli_stmt_execute($bloqueo)) throw new Exception('No se pudo bloquear el usuario.');
        mysqli_stmt_close($bloqueo);
        $tabla = mysqli_query($conexion, "SHOW TABLES LIKE 'dispositivos_confianza'");
        if (!$tabla) throw new Exception('No se pudo verificar el acceso de confianza.');
        if (mysqli_num_rows($tabla)) {
            $revocar = mysqli_prepare($conexion, 'DELETE FROM dispositivos_confianza WHERE usuario_id = ?');
            if (!$revocar) throw new Exception('No se pudieron preparar las revocaciones.');
            mysqli_stmt_bind_param($revocar, 'i', $id);
            if (!mysqli_stmt_execute($revocar)) throw new Exception('No se pudieron revocar los dispositivos de confianza.');
            mysqli_stmt_close($revocar);
        }
    }
    if (mysqli_stmt_execute($stmt)) {
        if ($transaccion) {
            if (!mysqli_commit($conexion)) throw new Exception('No se pudo confirmar la operación.');
            $transaccion = false;
        }
        if ($accion === 'agregar_usuario') {
            $auditoriaEntidadId = (int) mysqli_insert_id($conexion);
        }
        mysqli_stmt_close($stmt);
        nuviRegistrarAuditoria(
            $conexion,
            $auditoriaAccion,
            'usuario',
            $auditoriaEntidadId > 0 ? $auditoriaEntidadId : null,
            $auditoriaDescripcion,
            $auditoriaDetalles
        );
        echo json_encode(['status' => 'ok']);
    } else {
        if ($transaccion) {
            mysqli_rollback($conexion);
            $transaccion = false;
        }
        $err = mysqli_error($conexion);
        mysqli_stmt_close($stmt);
        error_log('[actualizar_usuarios] execute failed: ' . $err);
        echo json_encode(['status' => 'error', 'message' => $err]);
    }
} catch (Throwable $e) {
    if ($transaccion) mysqli_rollback($conexion);
    error_log('[actualizar_usuarios] exception: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
