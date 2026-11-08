<?php
require_once __DIR__ . '/session_utils.php';
require_once __DIR__ . '/csrf_utils.php';
require_once __DIR__ . '/dispositivos_confianza_utils.php';
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !nuviConfianzaHttps()
    || !is_string($_POST['csrf'] ?? null) || !hash_equals(nuviTokenCsrf(), $_POST['csrf'])) {
    http_response_code(403);
    exit('No se pudo verificar la solicitud.');
}
$selector = $_POST['selector'] ?? '';
$accion = $_POST['accion'] ?? '';
if (!in_array($accion, ['entrar', 'olvidar', 'olvidar_todos'], true)
    || ($accion !== 'olvidar_todos' && (!is_string($selector) || !preg_match('/^[a-f0-9]{32}$/D', $selector)))) {
    http_response_code(400);
    exit('Solicitud inválida.');
}
require_once __DIR__ . '/conexion.php';
try {
    if (!$conexion instanceof mysqli) throw new RuntimeException('Base no disponible');
    if ($accion === 'olvidar_todos') {
        foreach (array_keys(nuviConfianzaCookies()) as $perfilSelector) {
            nuviConfianzaEliminar($conexion, $perfilSelector);
        }
        header('Location: login.php');
        exit;
    }
    if ($accion === 'olvidar') {
        nuviConfianzaEliminar($conexion, $selector);
        header('Location: login.php');
        exit;
    }
    $perfiles = nuviConfianzaPerfiles($conexion);
    if (!isset($perfiles[$selector])) throw new RuntimeException('Perfil no válido o vencido');
    nuviConfianzaIniciarSesion($perfiles[$selector]);
    header('Location: punto_de_venta.php');
    exit;
} catch (Throwable $e) {
    error_log('Acceso de confianza: ' . $e->getMessage());
    header('Location: login.php?perfil_error=1');
    exit;
}
