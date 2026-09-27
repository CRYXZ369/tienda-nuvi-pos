<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$archivo = dirname(__DIR__) . '/config/local.php';
if (is_file($archivo)) {
    fwrite(STDERR, "Ya existe config/local.php; no se sobrescribió.\n");
    exit(1);
}

$configuracion = [
    'db_host' => getenv('NUVI_SETUP_DB_HOST') ?: '',
    'db_user' => getenv('NUVI_SETUP_DB_USER') ?: '',
    'db_password' => getenv('NUVI_SETUP_DB_PASSWORD') ?: '',
    'db_name' => getenv('NUVI_SETUP_DB_NAME') ?: '',
];

foreach ($configuracion as $valor) {
    if ($valor === '') {
        fwrite(STDERR, "Falta una variable privada para configurar la base de datos.\n");
        exit(1);
    }
}

$contenido = "<?php\nreturn " . var_export($configuracion, true) . ";\n";
if (file_put_contents($archivo, $contenido, LOCK_EX) === false) {
    fwrite(STDERR, "No se pudo escribir config/local.php.\n");
    exit(1);
}

@chmod($archivo, 0640);
fwrite(STDOUT, "Configuración local creada.\n");