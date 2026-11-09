<?php
require_once('auth.php');
tienePermiso(['admin']);
require_once('conexion.php');
require_once('abastecimiento_form_utils.php');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !nuviConsumirTokenAbastecimiento('migrar_pedidos', $_POST['token_operacion'] ?? null)) {
    http_response_code(403);
    exit('No se pudo verificar la solicitud. Recarga la página e inténtalo de nuevo.');
}
try {
    if (!mysqli_query($conexion, "CREATE TABLE IF NOT EXISTS proveedor_agenda (
        id_proveedor INT UNSIGNED NOT NULL PRIMARY KEY,
        dias_preventa VARCHAR(13) NOT NULL DEFAULT '',
        dias_entrega VARCHAR(13) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) throw new RuntimeException(mysqli_error($conexion));
    if (!mysqli_query($conexion, "CREATE TABLE IF NOT EXISTS proveedor_productos (
        id_proveedor INT UNSIGNED NOT NULL,
        id_producto BIGINT NOT NULL,
        unidades_paquete DECIMAL(12,3) DEFAULT NULL,
        PRIMARY KEY (id_proveedor, id_producto),
        KEY idx_proveedor_productos_producto (id_producto)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) throw new RuntimeException(mysqli_error($conexion));
    header('Location: pedidos_proveedor.php?estado=preparado');
} catch (Throwable $e) {
    error_log('migrate_pedidos_proveedor: ' . $e->getMessage());
    http_response_code(500);
    echo 'No se pudo preparar la agenda de proveedores. Consulta la bitácora del servidor y vuelve a intentarlo.';
}
