<?php
/** Migración idempotente para operación de inventario v1.3. */
header('Content-Type: application/json; charset=utf-8');
require_once('auth.php');
tienePermiso(['admin']);
require_once('conexion.php');
require_once('operacion_inventario_utils.php');

$cambios = [];

function nuviOperacionAgregarColumna(mysqli $conexion, string $tabla, string $columna, string $definicion, array &$cambios): void
{
    if (nuviOperacionColumnaExiste($conexion, $tabla, $columna)) return;
    $tablaSegura = str_replace('`', '``', $tabla);
    if (!mysqli_query($conexion, "ALTER TABLE `{$tablaSegura}` ADD COLUMN `{$columna}` {$definicion}")) {
        throw new Exception("No se pudo agregar {$tabla}.{$columna}: " . mysqli_error($conexion));
    }
    $cambios[] = "✓ {$tabla}.{$columna} agregada";
}

function nuviOperacionAsegurarIndice(mysqli $conexion, string $tabla, string $nombre, string $sql, array &$cambios): void
{
    $tablaSegura = str_replace('`', '``', $tabla);
    $indice = mysqli_query($conexion, "SHOW INDEX FROM `{$tablaSegura}` WHERE Key_name = '" . mysqli_real_escape_string($conexion, $nombre) . "'");
    if ($indice && mysqli_num_rows($indice) > 0) return;
    if (!mysqli_query($conexion, $sql)) {
        throw new Exception("No se pudo crear el índice {$nombre}: " . mysqli_error($conexion));
    }
    $cambios[] = "✓ Índice {$nombre} creado";
}

try {
    $tablas = [
        'proveedores' => "CREATE TABLE IF NOT EXISTS proveedores (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(120) NOT NULL,
            contacto VARCHAR(120) DEFAULT NULL,
            telefono VARCHAR(40) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            notas VARCHAR(500) DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_proveedor_nombre (nombre),
            KEY idx_proveedores_activos (activo, nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'compras' => "CREATE TABLE IF NOT EXISTS compras (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_proveedor INT UNSIGNED DEFAULT NULL,
            id_usuario INT DEFAULT NULL,
            fecha_compra DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            folio VARCHAR(80) DEFAULT NULL,
            total DECIMAL(14,2) NOT NULL DEFAULT 0,
            notas VARCHAR(500) DEFAULT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_compras_fecha (fecha_compra),
            KEY idx_compras_proveedor_fecha (id_proveedor, fecha_compra)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'compra_detalles' => "CREATE TABLE IF NOT EXISTS compra_detalles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_compra BIGINT UNSIGNED NOT NULL,
            id_producto INT NOT NULL,
            cantidad_recibida DECIMAL(12,3) NOT NULL,
            costo_unitario DECIMAL(12,4) NOT NULL,
            fecha_caducidad DATE DEFAULT NULL,
            lote VARCHAR(80) DEFAULT NULL,
            subtotal DECIMAL(14,2) NOT NULL,
            KEY idx_compra_detalles_compra (id_compra),
            KEY idx_compra_detalles_producto_caducidad (id_producto, fecha_caducidad)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'lotes_inventario' => "CREATE TABLE IF NOT EXISTS lotes_inventario (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_producto INT NOT NULL,
            id_compra_detalle BIGINT UNSIGNED DEFAULT NULL,
            fecha_caducidad DATE DEFAULT NULL,
            lote VARCHAR(80) DEFAULT NULL,
            cantidad_inicial DECIMAL(12,3) NOT NULL,
            cantidad_disponible DECIMAL(12,3) NOT NULL,
            costo_unitario DECIMAL(12,4) NOT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_lotes_producto_caducidad (id_producto, fecha_caducidad, cantidad_disponible),
            KEY idx_lotes_caducidad (fecha_caducidad, cantidad_disponible)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'movimientos_inventario' => "CREATE TABLE IF NOT EXISTS movimientos_inventario (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_producto INT NOT NULL,
            id_usuario INT DEFAULT NULL,
            tipo VARCHAR(20) NOT NULL,
            cantidad DECIMAL(12,3) NOT NULL,
            costo_unitario DECIMAL(12,4) NOT NULL DEFAULT 0,
            referencia_tipo VARCHAR(30) DEFAULT NULL,
            referencia_id BIGINT DEFAULT NULL,
            motivo VARCHAR(255) DEFAULT NULL,
            fecha_movimiento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_movimientos_producto_fecha (id_producto, fecha_movimiento),
            KEY idx_movimientos_tipo_fecha (tipo, fecha_movimiento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    foreach ($tablas as $nombre => $sql) {
        if (!mysqli_query($conexion, $sql)) {
            throw new Exception("No se pudo preparar {$nombre}: " . mysqli_error($conexion));
        }
        $cambios[] = "✓ Tabla {$nombre} disponible";
    }

    nuviOperacionAgregarColumna($conexion, 'detalle_ventas', 'costo_unitario', 'DECIMAL(12,4) DEFAULT NULL AFTER precio_unitario', $cambios);
    nuviOperacionAsegurarIndice($conexion, 'detalle_ventas', 'idx_detalle_costo', 'CREATE INDEX idx_detalle_costo ON detalle_ventas (id_producto, costo_unitario)', $cambios);

    echo json_encode([
        'success' => true,
        'message' => 'Operación de inventario preparada. No se modificaron productos ni ventas existentes.',
        'changes' => $cambios
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
