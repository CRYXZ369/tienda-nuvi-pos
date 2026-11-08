<?php
/** Utilidades compartidas para validar y persistir una venta desde el servidor. */
require_once __DIR__ . '/operacion_inventario_utils.php';

if (!function_exists('nuviNormalizarClaveIdempotenciaVenta')) {
    /**
     * Una misma petición puede reintentarse tras un corte de red. La clave
     * permite reconocerla sin volver a descontar inventario ni duplicar la
     * venta. Se acepta vacío para conservar compatibilidad antes de migrar.
     */
    function nuviNormalizarClaveIdempotenciaVenta($valor): string
    {
        $clave = trim((string) $valor);
        if ($clave === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $clave)) {
            throw new Exception('La clave de sincronización no es válida');
        }
        return $clave;
    }
}

if (!function_exists('nuviVentasSoportanIdempotencia')) {
    function nuviVentasSoportanIdempotencia(mysqli $conexion): bool
    {
        static $disponible = null;
        if ($disponible !== null) {
            return $disponible;
        }
        $columna = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'idempotency_key'");
        $indice = mysqli_query($conexion, "SHOW INDEX FROM ventas WHERE Key_name = 'uq_ventas_idempotency_key'");
        // La columna por sí sola no evita dos INSERT simultáneos. La garantía
        // real contra una venta duplicada es el índice UNIQUE de la migración.
        $disponible = $columna instanceof mysqli_result
            && mysqli_num_rows($columna) > 0
            && $indice instanceof mysqli_result
            && mysqli_num_rows($indice) > 0;
        return $disponible;
    }
}

if (!function_exists('nuviAsegurarUsuarioEnVentas')) {
    /**
     * Relaciona la venta con quien la cobró. Se prepara de forma compatible
     * con instalaciones existentes y nunca bloquea un cobro si la cuenta de
     * base de datos no tiene permiso para modificar la estructura.
     */
    function nuviAsegurarUsuarioEnVentas(mysqli $conexion): bool
    {
        $columna = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'id_usuario'");
        if ($columna instanceof mysqli_result && mysqli_num_rows($columna) > 0) {
            return true;
        }

        if (!mysqli_query($conexion, 'ALTER TABLE ventas ADD COLUMN id_usuario INT UNSIGNED DEFAULT NULL')) {
            error_log('Nuvi ventas: no se pudo agregar id_usuario: ' . mysqli_error($conexion));
            return false;
        }

        $indice = mysqli_query($conexion, "SHOW INDEX FROM ventas WHERE Key_name = 'idx_ventas_usuario'");
        if ($indice instanceof mysqli_result && mysqli_num_rows($indice) === 0) {
            mysqli_query($conexion, 'CREATE INDEX idx_ventas_usuario ON ventas (id_usuario)');
        }
        return true;
    }
}

if (!function_exists('nuviObtenerVentaIdempotente')) {
    /** @return array<string, mixed>|null */
    function nuviObtenerVentaIdempotente(mysqli $conexion, string $clave): ?array
    {
        if ($clave === '' || !nuviVentasSoportanIdempotencia($conexion)) {
            return null;
        }

        $stmt = mysqli_prepare(
            $conexion,
            'SELECT id_venta, total FROM ventas WHERE idempotency_key = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new Exception('No se pudo verificar la sincronización de venta');
        }
        mysqli_stmt_bind_param($stmt, 's', $clave);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('No se pudo verificar la sincronización de venta');
        }
        $resultado = mysqli_stmt_get_result($stmt);
        $venta = $resultado ? mysqli_fetch_assoc($resultado) : null;
        return $venta ?: null;
    }
}

if (!function_exists('nuviPrepararProductosVenta')) {
    /**
     * Bloquea los productos, valida que se puedan vender y calcula el importe
     * usando el precio registrado. Nunca se confía en precio, total o stock del
     * navegador.
     *
     * Debe llamarse dentro de una transacción.
     */
    function nuviPrepararProductosVenta(mysqli $conexion, array $productos, bool $permitirStockNegativo = false, array $preciosAutorizados = []): array
    {
        if (empty($productos)) {
            throw new Exception('El carrito está vacío');
        }

        static $tieneActivo = null;
        static $stockSinSigno = null;
        if ($tieneActivo === null) {
            $columna = mysqli_query($conexion, "SHOW COLUMNS FROM productos LIKE 'activo'");
            $tieneActivo = $columna && mysqli_num_rows($columna) > 0;
        }
        if ($permitirStockNegativo && $stockSinSigno === null) {
            $definicionStock = mysqli_query($conexion, "SHOW COLUMNS FROM productos LIKE 'stock'");
            $columnaStock = $definicionStock ? mysqli_fetch_assoc($definicionStock) : null;
            if (!$columnaStock) {
                throw new Exception('No se pudo verificar el formato del inventario.');
            }
            $stockSinSigno = stripos((string) $columnaStock['Type'], 'unsigned') !== false;
        }

        $campos = 'id, codigo_barras, nombre, precio_venta, precio_compra, stock, unidad_medida' . ($tieneActivo ? ', activo' : '');
        $stmtProducto = mysqli_prepare(
            $conexion,
            "SELECT $campos FROM productos WHERE (id = ? OR codigo_barras = ?) LIMIT 1 FOR UPDATE"
        );
        if (!$stmtProducto) {
            throw new Exception('No se pudo validar los productos');
        }

        $porLinea = [];
        $stockSolicitado = [];
        $stockDisponible = [];
        $indiceEntrada = 0;
        foreach ($productos as $producto) {
            $indiceEntrada++;
            if (!is_array($producto)) {
                throw new Exception('Producto inválido');
            }

            $referencia = trim((string) ($producto['id'] ?? $producto['codigo_barras'] ?? ''));
            $cantidad = (float) ($producto['cantidad'] ?? 0);
            if ($referencia === '' || $cantidad <= 0) {
                throw new Exception('Producto o cantidad inválidos');
            }

            $idBuscado = ctype_digit($referencia) ? (int) $referencia : 0;
            mysqli_stmt_bind_param($stmtProducto, 'is', $idBuscado, $referencia);
            if (!mysqli_stmt_execute($stmtProducto)) {
                throw new Exception('No se pudo validar un producto');
            }
            $resultado = mysqli_stmt_get_result($stmtProducto);
            $fila = $resultado ? mysqli_fetch_assoc($resultado) : null;
            if (!$fila) {
                throw new Exception('Un producto ya no existe');
            }
            if ($tieneActivo && isset($fila['activo']) && (int) $fila['activo'] !== 1) {
                throw new Exception('El producto ' . $fila['nombre'] . ' está pausado');
            }

            $idProducto = (int) $fila['id'];
            $precioVenta = (float) $fila['precio_venta'];
            if (array_key_exists($idProducto, $preciosAutorizados)) {
                $precioVenta = $preciosAutorizados[$idProducto];
                if (!is_numeric($precioVenta) || !is_finite((float) $precioVenta) || (float) $precioVenta <= 0) {
                    throw new Exception('El precio autorizado no es válido');
                }
                $precioVenta = (float) $precioVenta;
            }
            $unidad = strtolower(trim((string) ($fila['unidad_medida'] ?? '')));
            $esUnidadGranel = in_array($unidad, ['kg', 'g', 'l', 'lt', 'ml'], true);
            $esGranel = !empty($producto['origen_granel']) && $esUnidadGranel;
            $redondeo = strtolower(trim((string) ($producto['redondeo_granel'] ?? 'exacto')));
            if (!in_array($redondeo, ['exacto', 'abajo', 'arriba'], true)) {
                $redondeo = 'exacto';
            }
            if (!$esGranel) {
                $redondeo = 'exacto';
            }

            // Cada porción de granel conserva su redondeo individual. La clave
            // sólo separa líneas; precio y existencia siguen viniendo de la BD.
            $lineaGranel = trim((string) ($producto['linea_granel'] ?? ''));
            $lineaValida = preg_match('/^[A-Za-z0-9_-]{6,100}$/', $lineaGranel) === 1;
            $claveLinea = $idProducto . '|normal';
            if ($esGranel) {
                $claveLinea = $idProducto . '|granel|' . ($lineaValida ? $lineaGranel : 'sin_linea_' . $indiceEntrada);
            }

            if (!isset($porLinea[$claveLinea])) {
                $porLinea[$claveLinea] = [
                    'id' => $idProducto,
                    'nombre' => (string) $fila['nombre'],
                    'cantidad' => 0.0,
                    'precio' => $precioVenta,
                    'costo' => max(0, (float) ($fila['precio_compra'] ?? 0)),
                    'stock' => (float) $fila['stock'],
                    'es_granel' => $esGranel,
                    'redondeo_granel' => $redondeo,
                ];
            }

            $porLinea[$claveLinea]['cantidad'] += $cantidad;
            $stockSolicitado[$idProducto] = ($stockSolicitado[$idProducto] ?? 0.0) + $cantidad;
            $stockDisponible[$idProducto] = (float) $fila['stock'];
            if (!$permitirStockNegativo && $stockSolicitado[$idProducto] > $stockDisponible[$idProducto] + 0.000001) {
                throw new Exception('Stock insuficiente para ' . $fila['nombre']);
            }
            if ($permitirStockNegativo && $stockSinSigno && $stockSolicitado[$idProducto] > $stockDisponible[$idProducto] + 0.000001) {
                throw new Exception('El inventario aún no admite existencias negativas. Un administrador debe actualizar su estructura.');
            }
        }

        $total = 0.0;
        foreach ($porLinea as &$producto) {
            $subtotalBase = round($producto['cantidad'] * $producto['precio'], 2);
            if ($producto['es_granel'] && $producto['redondeo_granel'] === 'abajo') {
                $producto['subtotal'] = floor($subtotalBase + 0.000001);
            } elseif ($producto['es_granel'] && $producto['redondeo_granel'] === 'arriba') {
                $producto['subtotal'] = ceil($subtotalBase - 0.000001);
            } else {
                $producto['subtotal'] = $subtotalBase;
            }
            if ($producto['subtotal'] <= 0) {
                throw new Exception('El redondeo no puede dejar una venta en $0');
            }
            $total += $producto['subtotal'];
        }
        unset($producto);

        $total = round($total, 2);
        if ($total <= 0) {
            throw new Exception('El total calculado no es válido');
        }

        return ['productos' => array_values($porLinea), 'total' => $total];
    }
}

if (!function_exists('nuviGuardarDetallesYDescontarStock')) {
    /** Guarda detalles y descuenta stock dentro de la transacción de venta. */
    function nuviGuardarDetallesYDescontarStock(mysqli $conexion, int $idVenta, array $productos, bool $permitirStockNegativo = false): void
    {
        static $tieneSubtotalDetalle = null;
        static $tieneCostoUnitario = null;
        if ($tieneSubtotalDetalle === null) {
            $columna = mysqli_query($conexion, "SHOW COLUMNS FROM detalle_ventas LIKE 'subtotal'");
            $tieneSubtotalDetalle = $columna instanceof mysqli_result && mysqli_num_rows($columna) > 0;
        }
        if ($tieneCostoUnitario === null) {
            $columna = mysqli_query($conexion, "SHOW COLUMNS FROM detalle_ventas LIKE 'costo_unitario'");
            $tieneCostoUnitario = $columna instanceof mysqli_result && mysqli_num_rows($columna) > 0;
        }
        $stmtDetalle = mysqli_prepare(
            $conexion,
            $tieneCostoUnitario
                ? ($tieneSubtotalDetalle
                    ? 'INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_unitario, costo_unitario, subtotal) VALUES (?, ?, ?, ?, ?, ?)'
                    : 'INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_unitario, costo_unitario) VALUES (?, ?, ?, ?, ?)')
                : ($tieneSubtotalDetalle
                    ? 'INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)'
                    : 'INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_unitario) VALUES (?, ?, ?, ?)')
        );
        $stmtStock = mysqli_prepare(
            $conexion,
            $permitirStockNegativo
                ? 'UPDATE productos SET stock = stock - ? WHERE id = ?'
                : 'UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        if (!$stmtDetalle || !$stmtStock) {
            throw new Exception('No se pudo preparar el detalle de venta');
        }

        foreach ($productos as $producto) {
            $idProducto = (int) $producto['id'];
            $cantidad = (float) $producto['cantidad'];
            $precio = (float) $producto['precio'];
            $costo = max(0, (float) ($producto['costo'] ?? 0));
            $subtotal = (float) ($producto['subtotal'] ?? round($cantidad * $precio, 2));

            if ($tieneCostoUnitario && $tieneSubtotalDetalle) {
                mysqli_stmt_bind_param($stmtDetalle, 'iidddd', $idVenta, $idProducto, $cantidad, $precio, $costo, $subtotal);
            } elseif ($tieneCostoUnitario) {
                mysqli_stmt_bind_param($stmtDetalle, 'iiddd', $idVenta, $idProducto, $cantidad, $precio, $costo);
            } elseif ($tieneSubtotalDetalle) {
                mysqli_stmt_bind_param($stmtDetalle, 'iiddd', $idVenta, $idProducto, $cantidad, $precio, $subtotal);
            } else {
                mysqli_stmt_bind_param($stmtDetalle, 'iidd', $idVenta, $idProducto, $cantidad, $precio);
            }
            if (!mysqli_stmt_execute($stmtDetalle)) {
                throw new Exception('No se pudo guardar el detalle de venta');
            }

            if ($permitirStockNegativo) {
                mysqli_stmt_bind_param($stmtStock, 'di', $cantidad, $idProducto);
            } else {
                mysqli_stmt_bind_param($stmtStock, 'did', $cantidad, $idProducto, $cantidad);
            }
            if (!mysqli_stmt_execute($stmtStock) || mysqli_stmt_affected_rows($stmtStock) !== 1) {
                throw new Exception('El stock cambió antes de concluir la venta');
            }
            nuviConsumirLotesInventario($conexion, $idProducto, $cantidad);
        }
    }
}
