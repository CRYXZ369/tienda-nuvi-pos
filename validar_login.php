<?php
require_once __DIR__ . '/session_utils.php';
include("conexion.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = mysqli_real_escape_string($conexion, $_POST['usuario']);
    $pass = $_POST['password'];

    // Buscamos al usuario
    $sql = "SELECT * FROM usuarios WHERE usuario = ? AND estado = 1 LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "s", $user);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);

    if ($fila = mysqli_fetch_assoc($resultado)) {
        // Verificamos el hash de la contraseña
        if (password_verify($pass, $fila['password'])) {
            session_regenerate_id(true);
            // Guardamos los datos en el casillero (Sesión)
            $_SESSION['id_usuario'] = $fila['id'];
            $_SESSION['nombre'] = $fila['nombre'];
            $_SESSION['rol'] = $fila['rol'];
            $_SESSION['tema_ui'] = ($fila['tema_ui'] ?? 'claro') === 'oscuro' ? 'oscuro' : 'claro';

            // Redirigimos según el rol
            header("Location: punto_de_venta.php");
            exit;
        }
    }

    // Si algo falla, lo regresamos con error
    header("Location: login.php?error=1");
    exit;
}
