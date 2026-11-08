<?php
require_once __DIR__ . '/session_utils.php';
require_once __DIR__ . '/csrf_utils.php';
require_once __DIR__ . '/dispositivos_confianza_utils.php';
include("conexion.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(nuviTokenCsrf(), $_POST['csrf'])) {
        http_response_code(403);
        exit('No se pudo verificar la solicitud. Regresa al inicio de sesión e inténtalo de nuevo.');
    }
    if (!$conexion instanceof mysqli) {
        http_response_code(503);
        exit('No fue posible conectar con la base de datos.');
    }
    $user = (string) ($_POST['usuario'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');

    // Buscamos al usuario
    $sql = "SELECT * FROM usuarios WHERE usuario = ? AND estado = 1 LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "s", $user);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);

    if ($fila = mysqli_fetch_assoc($resultado)) {
        // Verificamos el hash de la contraseña
        if (password_verify($pass, $fila['password'])) {
            $aviso = '';
            if (($_POST['confiar'] ?? '') === '1') {
                try {
                    $existentes = nuviConfianzaPerfiles($conexion);
                    $yaExiste = false;
                    foreach ($existentes as $perfil) {
                        if ((int) $perfil['id'] === (int) $fila['id']) $yaExiste = true;
                    }
                    if (!$yaExiste) nuviConfianzaCrear($conexion, $fila);
                } catch (Throwable $e) {
                    error_log('No se pudo guardar perfil de confianza: ' . $e->getMessage());
                    $aviso = 'Iniciaste sesión, pero no se pudo guardar el perfil de confianza. Verifica HTTPS y que la función esté instalada.';
                }
            }
            nuviConfianzaIniciarSesion($fila);
            if ($aviso !== '') {
                header('Cache-Control: no-store');
                echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><p role="alert">'
                    . htmlspecialchars($aviso, ENT_QUOTES, 'UTF-8')
                    . '</p><p><a href="punto_de_venta.php">Continuar a la caja</a></p>';
                exit;
            }

            // Redirigimos según el rol
            header("Location: punto_de_venta.php");
            exit;
        }
    }

    // Si algo falla, lo regresamos con error
    header("Location: login.php?error=1");
    exit;
}
