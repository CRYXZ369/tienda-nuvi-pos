<?php
// Migración idempotente: conserva la configuración previa y permite varias terminales.
require_once('auth.php');
tienePermiso(['admin']);
require_once('conexion.php');
header('Content-Type: application/json; charset=utf-8');

$cambios = [];
function nuviMigrarColumna(mysqli $conexion, string $columna, string $definicion, array &$cambios): void
{
    $res = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE '" . mysqli_real_escape_string($conexion, $columna) . "'");
    if ($res && mysqli_num_rows($res) > 0) {
        $cambios[] = "✓ Columna '{$columna}' ya existe en ventas";
        return;
    }
    if (!mysqli_query($conexion, "ALTER TABLE ventas ADD COLUMN {$columna} {$definicion}")) {
        throw new Exception("No se pudo agregar {$columna}: " . mysqli_error($conexion));
    }
    $cambios[] = "✓ Columna '{$columna}' agregada a ventas";
}

function nuviMigrarColumnaEnTabla(mysqli $conexion, string $tabla, string $columna, string $definicion, array &$cambios): void
{
    $tablaSeguro = '`' . str_replace('`', '', $tabla) . '`';
    $columnaSegura = mysqli_real_escape_string($conexion, $columna);
    $res = mysqli_query($conexion, "SHOW COLUMNS FROM $tablaSeguro LIKE '$columnaSegura'");
    if ($res && mysqli_num_rows($res) > 0) {
        return;
    }
    if (!mysqli_query($conexion, "ALTER TABLE $tablaSeguro ADD COLUMN `$columna` $definicion")) {
        throw new Exception("No se pudo agregar $tabla.$columna: " . mysqli_error($conexion));
    }
    $cambios[] = "✓ $tabla: campo '$columna' agregado";
}

function nuviEscalaDecimalActual(?array $definicion): int
{
    $tipo = strtolower((string) ($definicion['Type'] ?? ''));
    if (preg_match('/decimal\(\d+,\s*(\d+)\)/', $tipo, $coincidencia)) {
        return (int) $coincidencia[1];
    }
    return 0;
}

function nuviAsegurarDecimal(mysqli $conexion, string $tabla, string $columna, string $definicion, int $escalaMinima, string $descripcion, array &$cambios): void
{
    $res = mysqli_query($conexion, "SHOW COLUMNS FROM `$tabla` LIKE '$columna'");
    $actual = $res ? mysqli_fetch_assoc($res) : null;
    if ($actual && nuviEscalaDecimalActual($actual) >= $escalaMinima) {
        $cambios[] = "✓ $descripcion ya tiene precisión decimal compatible";
        return;
    }
    if (!mysqli_query($conexion, "ALTER TABLE `$tabla` MODIFY `$columna` $definicion")) {
        throw new Exception("No se pudo actualizar $descripcion: " . mysqli_error($conexion));
    }
    $cambios[] = "✓ $descripcion actualizado para ventas a granel";
}

try {
    if (!mysqli_query($conexion, "CREATE TABLE IF NOT EXISTS config_comisiones (
        id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
        comision_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 3.50,
        iva_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 16.00,
        cargo_fijo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tiempo_acreditacion VARCHAR(100) NOT NULL DEFAULT 'Al día siguiente hábil',
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new Exception('No se pudo preparar la configuración anterior: ' . mysqli_error($conexion));
    }
    mysqli_query($conexion, 'INSERT IGNORE INTO config_comisiones (id) VALUES (1)');

    if (!mysqli_query($conexion, "CREATE TABLE IF NOT EXISTS terminales_pago (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(80) NOT NULL,
        comision_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 3.50,
        iva_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 16.00,
        cargo_fijo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tiempo_acreditacion VARCHAR(100) NOT NULL DEFAULT 'Al día siguiente hábil',
        activo TINYINT(1) NOT NULL DEFAULT 1,
        es_predeterminada TINYINT(1) NOT NULL DEFAULT 0,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_terminales_pago_nombre (nombre),
        KEY idx_terminales_pago_activas (activo, es_predeterminada)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new Exception('No se pudo crear el catálogo de terminales: ' . mysqli_error($conexion));
    }

    // Las puntuaciones sólo ordenan búsquedas del POS. Prepararlas una vez en
    // la migración evita ejecutar DDL en cada carga de una caja.
    if (!mysqli_query($conexion, "CREATE TABLE IF NOT EXISTS pos_scores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        producto_id INT NOT NULL,
        score DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY usuario_producto (id_usuario, producto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new Exception('No se pudo preparar las preferencias de búsqueda: ' . mysqli_error($conexion));
    }

    // Bitácora separada del log técnico: permite reconstruir cambios de
    // dinero, inventario y configuración sin guardar contraseñas ni datos
    // sensibles. LONGTEXT conserva compatibilidad con instalaciones MySQL
    // antiguas donde JSON no está disponible.
    if (!mysqli_query($conexion, "CREATE TABLE IF NOT EXISTS bitacora_auditoria (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        id_usuario INT DEFAULT NULL,
        accion VARCHAR(60) NOT NULL,
        entidad VARCHAR(60) NOT NULL,
        entidad_id BIGINT DEFAULT NULL,
        descripcion VARCHAR(255) NOT NULL,
        detalles_json LONGTEXT DEFAULT NULL,
        ip_origen VARCHAR(45) DEFAULT NULL,
        KEY idx_bitacora_fecha (fecha),
        KEY idx_bitacora_usuario_fecha (id_usuario, fecha),
        KEY idx_bitacora_entidad (entidad, entidad_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new Exception('No se pudo preparar la bitácora operativa: ' . mysqli_error($conexion));
    }
    $cambios[] = '✓ Bitácora operativa disponible para administración';

    $conteo = mysqli_query($conexion, 'SELECT COUNT(*) AS total FROM terminales_pago');
    $totalTerminales = (int) (mysqli_fetch_assoc($conteo)['total'] ?? 0);
    if ($totalTerminales === 0) {
        $anteriorRes = mysqli_query($conexion, 'SELECT comision_porcentaje, iva_porcentaje, cargo_fijo, tiempo_acreditacion FROM config_comisiones WHERE id = 1');
        $anterior = $anteriorRes ? mysqli_fetch_assoc($anteriorRes) : [];
        $nombre = 'Terminal principal';
        $comision = (float) ($anterior['comision_porcentaje'] ?? 3.50);
        $iva = (float) ($anterior['iva_porcentaje'] ?? 16.00);
        $fijo = (float) ($anterior['cargo_fijo'] ?? 0);
        $tiempo = trim((string) ($anterior['tiempo_acreditacion'] ?? '')) ?: 'Al día siguiente hábil';
        $stmt = mysqli_prepare($conexion, 'INSERT INTO terminales_pago (nombre, comision_porcentaje, iva_porcentaje, cargo_fijo, tiempo_acreditacion, activo, es_predeterminada) VALUES (?, ?, ?, ?, ?, 1, 1)');
        mysqli_stmt_bind_param($stmt, 'sddds', $nombre, $comision, $iva, $fijo, $tiempo);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('No se pudo migrar la terminal existente: ' . mysqli_stmt_error($stmt));
        }
        $cambios[] = '✓ Configuración anterior conservada como “Terminal principal”';
    } else {
        $predeterminada = mysqli_query($conexion, 'SELECT id FROM terminales_pago WHERE activo = 1 AND es_predeterminada = 1 LIMIT 1');
        if (!$predeterminada || mysqli_num_rows($predeterminada) === 0) {
            mysqli_query($conexion, 'UPDATE terminales_pago SET es_predeterminada = 1 WHERE activo = 1 ORDER BY id ASC LIMIT 1');
        }
        $cambios[] = '✓ Catálogo de terminales ya existe';
    }

    nuviMigrarColumna($conexion, 'comision_tarjeta', 'DECIMAL(10,2) DEFAULT NULL', $cambios);
    nuviMigrarColumna($conexion, 'monto_neto_tarjeta', 'DECIMAL(10,2) DEFAULT NULL', $cambios);
    nuviMigrarColumna($conexion, 'monto_cobrado_tarjeta', 'DECIMAL(10,2) DEFAULT NULL', $cambios);
    nuviMigrarColumna($conexion, 'recargo_cliente_tarjeta', 'DECIMAL(10,2) DEFAULT NULL', $cambios);
    nuviMigrarColumna($conexion, 'id_terminal_pago', 'INT UNSIGNED DEFAULT NULL', $cambios);
    nuviMigrarColumna($conexion, 'terminal_nombre', 'VARCHAR(80) DEFAULT NULL', $cambios);
    nuviMigrarColumna($conexion, 'nota_venta', 'VARCHAR(255) DEFAULT NULL', $cambios);
    // Evita duplicar una venta si una tablet pierde la respuesta y reintenta
    // sincronizarla cuando vuelve la red local.
    nuviMigrarColumna($conexion, 'idempotency_key', 'VARCHAR(80) DEFAULT NULL', $cambios);

    $indice = mysqli_query($conexion, "SHOW INDEX FROM ventas WHERE Key_name = 'idx_ventas_terminal_pago'");
    if ($indice && mysqli_num_rows($indice) === 0) {
        mysqli_query($conexion, 'CREATE INDEX idx_ventas_terminal_pago ON ventas (id_terminal_pago)');
    }
    $indiceIdempotencia = mysqli_query($conexion, "SHOW INDEX FROM ventas WHERE Key_name = 'uq_ventas_idempotency_key'");
    if ($indiceIdempotencia && mysqli_num_rows($indiceIdempotencia) === 0) {
        if (!mysqli_query($conexion, 'CREATE UNIQUE INDEX uq_ventas_idempotency_key ON ventas (idempotency_key)')) {
            throw new Exception('No se pudo activar la protección contra ventas duplicadas: ' . mysqli_error($conexion));
        }
        $cambios[] = '✓ Ventas: protección contra reintentos duplicados habilitada';
    }

    // Libreta v1.2: se conserva todo el historial y se habilitan cargos/ajustes
    // identificables en vez de disfrazarlos como una venta nueva.
    $colUsuarioLibreta = mysqli_query($conexion, "SHOW COLUMNS FROM libreta_digital LIKE 'id_usuario'");
    if ($colUsuarioLibreta && mysqli_num_rows($colUsuarioLibreta) === 0) {
        if (!mysqli_query($conexion, 'ALTER TABLE libreta_digital ADD COLUMN id_usuario INT DEFAULT NULL AFTER id_cliente')) {
            throw new Exception('No se pudo agregar id_usuario a libreta: ' . mysqli_error($conexion));
        }
        $cambios[] = "✓ Libreta: columna 'id_usuario' agregada";
    }
    $tipoLibreta = mysqli_query($conexion, "SHOW COLUMNS FROM libreta_digital LIKE 'tipo_movimiento'");
    $defTipoLibreta = $tipoLibreta ? mysqli_fetch_assoc($tipoLibreta) : null;
    if ($defTipoLibreta && stripos((string) $defTipoLibreta['Type'], 'enum(') === 0 && stripos((string) $defTipoLibreta['Type'], "'recargo'") === false) {
        if (!mysqli_query($conexion, "ALTER TABLE libreta_digital MODIFY tipo_movimiento ENUM('deuda','abono','recargo','ajuste') NOT NULL")) {
            throw new Exception('No se pudo actualizar los tipos de libreta: ' . mysqli_error($conexion));
        }
        $cambios[] = '✓ Libreta: tipos de recargo y ajuste habilitados';
    }

    $colClienteActivo = mysqli_query($conexion, "SHOW COLUMNS FROM clientes LIKE 'activo'");
    if ($colClienteActivo && mysqli_num_rows($colClienteActivo) === 0) {
        if (!mysqli_query($conexion, 'ALTER TABLE clientes ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1')) {
            throw new Exception('No se pudo agregar estado a clientes: ' . mysqli_error($conexion));
        }
        $cambios[] = '✓ Libreta: clientes archivables sin borrar historial';
    }

    // Interés simple de libreta. Se inicializa sólo una vez sobre saldos ya
    // existentes; no modifica el dinero adeudado ni reescribe el historial.
    nuviMigrarColumnaEnTabla($conexion, 'clientes', 'fecha_inicio_interes', 'DATE DEFAULT NULL', $cambios);
    nuviMigrarColumnaEnTabla($conexion, 'clientes', 'saldo_base_interes', 'DECIMAL(12,2) NOT NULL DEFAULT 0', $cambios);
    nuviMigrarColumnaEnTabla($conexion, 'clientes', 'semanas_interes_aplicadas', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0', $cambios);
    $interesInicializado = mysqli_query($conexion, "UPDATE clientes
        SET fecha_inicio_interes = CURDATE(), saldo_base_interes = ROUND(saldo_actual, 2), semanas_interes_aplicadas = 0
        WHERE COALESCE(saldo_actual, 0) > 0 AND fecha_inicio_interes IS NULL");
    if ($interesInicializado === false) {
        throw new Exception('No se pudo preparar la regla de interés simple: ' . mysqli_error($conexion));
    }
    if (mysqli_affected_rows($conexion) > 0) {
        $cambios[] = '✓ Libreta: saldos actuales preparados para interés simple desde hoy';
    } else {
        $cambios[] = '✓ Libreta: regla de interés simple ya preparada';
    }

    $colTemaUsuario = mysqli_query($conexion, "SHOW COLUMNS FROM usuarios LIKE 'tema_ui'");
    if ($colTemaUsuario && mysqli_num_rows($colTemaUsuario) === 0) {
        if (!mysqli_query($conexion, "ALTER TABLE usuarios ADD COLUMN tema_ui ENUM('claro','oscuro') NOT NULL DEFAULT 'claro'")) {
            throw new Exception('No se pudo agregar la preferencia visual de usuarios: ' . mysqli_error($conexion));
        }
        $cambios[] = '✓ Usuarios: preferencia de tema visual habilitada';
    }

    // Inventario, detalle y precios conservan fracciones para productos por
    // kilo, gramo, litro o mililitro. Cuatro decimales de precio permiten que
    // una mercancía registrada por gramo no pierda su valor real.
    $stockInfo = mysqli_query($conexion, "SHOW COLUMNS FROM productos LIKE 'stock'");
    $stockDef = $stockInfo ? mysqli_fetch_assoc($stockInfo) : null;
    if (!$stockDef) {
        throw new Exception('No existe la columna de stock del inventario');
    }
    nuviAsegurarDecimal($conexion, 'productos', 'stock', 'DECIMAL(12,3) NOT NULL DEFAULT 0', 3, 'Inventario: stock', $cambios);
    $cantidadInfo = mysqli_query($conexion, "SHOW COLUMNS FROM detalle_ventas LIKE 'cantidad'");
    $cantidadDef = $cantidadInfo ? mysqli_fetch_assoc($cantidadInfo) : null;
    if (!$cantidadDef) {
        throw new Exception('No existe la cantidad de detalle de venta');
    }
    nuviAsegurarDecimal($conexion, 'detalle_ventas', 'cantidad', 'DECIMAL(12,3) NOT NULL', 3, 'Ventas: cantidad', $cambios);
    nuviAsegurarDecimal($conexion, 'productos', 'precio_compra', 'DECIMAL(12,4) NOT NULL DEFAULT 0', 4, 'Inventario: precio de compra', $cambios);
    nuviAsegurarDecimal($conexion, 'productos', 'precio_venta', 'DECIMAL(12,4) NOT NULL', 4, 'Inventario: precio de venta', $cambios);
    nuviAsegurarDecimal($conexion, 'detalle_ventas', 'precio_unitario', 'DECIMAL(12,4) NOT NULL', 4, 'Ventas: precio unitario', $cambios);

    $indicesV12 = [
        ['tabla' => 'productos', 'nombre' => 'idx_productos_activo_stock_id', 'sql' => 'CREATE INDEX idx_productos_activo_stock_id ON productos (activo, stock, id)'],
        ['tabla' => 'libreta_digital', 'nombre' => 'idx_libreta_cliente_fecha', 'sql' => 'CREATE INDEX idx_libreta_cliente_fecha ON libreta_digital (id_cliente, fecha_movimiento)'],
        ['tabla' => 'detalle_ventas', 'nombre' => 'idx_detalle_venta_producto', 'sql' => 'CREATE INDEX idx_detalle_venta_producto ON detalle_ventas (id_venta, id_producto)'],
        ['tabla' => 'ventas', 'nombre' => 'idx_ventas_fecha', 'sql' => 'CREATE INDEX idx_ventas_fecha ON ventas (fecha_venta)'],
    ];
    foreach ($indicesV12 as $indiceV12) {
        $existeIndice = mysqli_query($conexion, "SHOW INDEX FROM `{$indiceV12['tabla']}` WHERE Key_name = '{$indiceV12['nombre']}'");
        if ($existeIndice && mysqli_num_rows($existeIndice) === 0 && mysqli_query($conexion, $indiceV12['sql'])) {
            $cambios[] = "✓ Rendimiento: índice {$indiceV12['nombre']} creado";
        }
    }

    echo json_encode(['success' => true, 'message' => 'Migración completada', 'changes' => $cambios], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
