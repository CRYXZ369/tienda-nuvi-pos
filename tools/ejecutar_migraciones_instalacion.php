<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));
session_id(bin2hex(random_bytes(32)));
session_start();
$_SESSION['id_usuario'] = 1;
$_SESSION['rol'] = 'admin';

$migraciones = [
    'migrate_comisiones_tarjeta.php',
    'migrate_detalle_cantidad_decimal.php',
    'migrate_libreta_usuario.php',
    'migrate_nota_venta.php',
    'migrate_operacion_inventario.php',
    'migrate_performance_indexes.php',
    'migrate_dispositivos_confianza.php',
];

foreach ($migraciones as $migracion) {
    ob_start();
    try {
        require $migracion;
        $salida = ob_get_clean();
    } catch (Throwable $error) {
        ob_end_clean();
        fwrite(STDERR, "Falló {$migracion}: {$error->getMessage()}\n");
        $_SESSION = [];
        session_destroy();
        exit(1);
    }

    $respuesta = json_decode(trim($salida), true);
    if (!is_array($respuesta) || ($respuesta['success'] ?? false) !== true) {
        fwrite(STDERR, "Falló {$migracion}: " . trim($salida) . "\n");
        $_SESSION = [];
        session_destroy();
        exit(1);
    }
    fwrite(STDOUT, "OK {$migracion}\n");
}

$_SESSION = [];
session_destroy();