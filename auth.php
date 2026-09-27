<?php
require_once __DIR__ . '/session_utils.php';

if (!function_exists('nuviEsSolicitudAsincrona')) {
    /**
     * Las pantallas normales siguen redirigiendo al login. Las peticiones
     * fetch, en cambio, necesitan JSON y un 401 para no confundir una página
     * de login con una respuesta inválida del servidor.
     */
    function nuviEsSolicitudAsincrona(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $solicitadaCon = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $modoFetch = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));

        return strpos($accept, 'application/json') !== false
            || $solicitadaCon === 'xmlhttprequest'
            || ($modoFetch !== '' && $modoFetch !== 'navigate');
    }
}

// Si no existe la variable de sesión 'id_usuario', significa que no ha hecho login
//por lo tanto la sesion esta verificada, pero no autorizada, por lo tanto se redirige al login
if (!isset($_SESSION['id_usuario'])) {
    if (nuviEsSolicitudAsincrona()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode([
            'success' => false,
            'error' => 'SESSION_EXPIRED',
            'message' => 'La sesión venció. Inicia sesión nuevamente para continuar.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    header("Location: login.php");
    exit();
}

// Mantiene vigente el archivo de sesión durante una jornada de caja activa.
$_SESSION['ultima_actividad'] = time();

// Opcional: Función para restringir por rol
function tienePermiso($rolesPermitidos) {
    if (!in_array($_SESSION['rol'] ?? '', $rolesPermitidos, true)) {
        echo "<script>alert('Acceso Denegado: No tienes permisos para esta sección.'); window.location.href='punto_de_venta.php';</script>";
        exit();
    }
}
?>
