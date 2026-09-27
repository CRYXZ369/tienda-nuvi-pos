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

$archivo = dirname(__DIR__) . '/database/schema.sql';
$sql = is_file($archivo) ? file_get_contents($archivo) : false;
if ($sql === false || !preg_match('/^CREATE TABLE /m', $sql) || preg_match('/^\s*(INSERT|REPLACE|UPDATE|DELETE|DROP)\b/im', $sql)) {
    fwrite(STDERR, "El esquema inicial falta o contiene sentencias no permitidas.\n");
    exit(1);
}

if (!mysqli_multi_query($conexion, $sql)) {
    fwrite(STDERR, 'No se pudo importar el esquema: ' . mysqli_error($conexion) . "\n");
    exit(1);
}

do {
    $resultado = mysqli_store_result($conexion);
    if ($resultado instanceof mysqli_result) {
        mysqli_free_result($resultado);
    }
    if (mysqli_more_results($conexion) && !mysqli_next_result($conexion)) {
        fwrite(STDERR, 'Falló una sentencia del esquema: ' . mysqli_error($conexion) . "\n");
        exit(1);
    }
} while (mysqli_more_results($conexion));

fwrite(STDOUT, "Esquema vacío importado.\n");