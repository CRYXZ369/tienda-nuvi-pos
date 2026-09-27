<?php
/**
 * Conexión centralizada. Las credenciales viven fuera del repositorio en
 * config/local.php o en variables de entorno para que GitHub nunca las reciba.
 */
$configuracion = [];
$archivoLocal = __DIR__ . '/config/local.php';
if (is_file($archivoLocal)) {
    $cargada = require $archivoLocal;
    if (is_array($cargada)) {
        $configuracion = $cargada;
    }
}

$host = (string) ($configuracion['db_host'] ?? (getenv('NUVI_DB_HOST') ?: '127.0.0.1'));
$user = (string) ($configuracion['db_user'] ?? (getenv('NUVI_DB_USER') ?: ''));
$pass = (string) ($configuracion['db_password'] ?? (getenv('NUVI_DB_PASSWORD') ?: ''));
$db = (string) ($configuracion['db_name'] ?? (getenv('NUVI_DB_NAME') ?: 'tienda_nuvi'));

$conexion = null;
if (!function_exists('mysqli_connect')) {
    error_log('Extensión mysqli no disponible en este entorno');
} elseif ($user === '') {
    error_log('Falta la configuración de base de datos en config/local.php o variables NUVI_DB_*');
} else {
    $conexion = @mysqli_connect($host, $user, $pass, $db);
    if (!$conexion) {
        error_log('Fallo de conexión MySQL');
    } else {
        mysqli_set_charset($conexion, 'utf8mb4');
    }
}
