<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('conexion.php');
require_once('auditoria_utils.php');

function reporteRedirigirError($codigo)
{
    header('Location: reportes.php?error=' . rawurlencode($codigo), true, 303);
    exit;
}

function reporteFechaValida($fecha)
{
    $fecha = trim((string) $fecha);
    $objeto = DateTime::createFromFormat('!Y-m-d', $fecha);
    $errores = DateTime::getLastErrors();
    return $objeto && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0)) && $objeto->format('Y-m-d') === $fecha;
}

function reporteColumnaExiste($conexion, $tabla, $columna)
{
    static $cache = [];
    $clave = $tabla . '.' . $columna;
    if (array_key_exists($clave, $cache)) return $cache[$clave];
    $tablaSegura = str_replace('`', '``', $tabla);
    $columnaSegura = mysqli_real_escape_string($conexion, $columna);
    $resultado = mysqli_query($conexion, "SHOW COLUMNS FROM `{$tablaSegura}` LIKE '{$columnaSegura}'");
    return $cache[$clave] = ($resultado instanceof mysqli_result && mysqli_num_rows($resultado) > 0);
}

function reporteTablaExiste($conexion, $tabla)
{
    $tablaSegura = mysqli_real_escape_string($conexion, $tabla);
    $resultado = mysqli_query($conexion, "SHOW TABLES LIKE '{$tablaSegura}'");
    return $resultado instanceof mysqli_result && mysqli_num_rows($resultado) > 0;
}

function reporteCeldaCsv($valor)
{
    if ($valor === null) return '';
    $texto = (string) $valor;
    // Evita que Excel ejecute fórmulas introducidas en notas, nombres o folios.
    if (preg_match('/^\s*[=+\-@]/u', $texto)) $texto = "'" . $texto;
    return $texto;
}

function reporteDescargarCsv($nombre, array $encabezados, $resultado)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF"); // UTF-8 para que Excel respete acentos.
    fputcsv($salida, $encabezados, ';');
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $valores = [];
        foreach ($encabezados as $clave) $valores[] = reporteCeldaCsv($fila[$clave] ?? '');
        fputcsv($salida, $valores, ';');
    }
    fclose($salida);
    exit;
}

if (!isset($conexion) || !($conexion instanceof mysqli)) reporteRedirigirError('error_reporte');

$tipo = trim((string) ($_GET['tipo'] ?? ''));
$tipos = ['ventas', 'resumen_ventas', 'cortes', 'inventario', 'libreta', 'abastecimiento'];
if (!in_array($tipo, $tipos, true)) reporteRedirigirError('tipo_invalido');

$desde = trim((string) ($_GET['desde'] ?? date('Y-m-01')));
$hasta = trim((string) ($_GET['hasta'] ?? date('Y-m-d')));
if (!reporteFechaValida($desde) || !reporteFechaValida($hasta) || $desde > $hasta) reporteRedirigirError('fecha_invalida');
try {
    $dias = (new DateTime($desde))->diff(new DateTime($hasta))->days;
    if ($dias > 731) reporteRedirigirError('periodo_demasiado_largo');
} catch (Throwable $error) {
    reporteRedirigirError('fecha_invalida');
}

try {
    $fechaDesde = mysqli_real_escape_string($conexion, $desde . ' 00:00:00');
    $fechaHasta = mysqli_real_escape_string($conexion, $hasta . ' 23:59:59');
    $resultado = false;
    $encabezados = [];
    $nombre = 'nuvi_' . $tipo . '_' . $desde . '_a_' . $hasta;

    if ($tipo === 'ventas' || $tipo === 'resumen_ventas') {
        $campoMetodo = reporteColumnaExiste($conexion, 'ventas', 'metodo_pago') ? "COALESCE(NULLIF(v.metodo_pago, ''), 'efectivo')" : "'efectivo'";
        $campoTerminal = reporteColumnaExiste($conexion, 'ventas', 'terminal_nombre') ? 'COALESCE(v.terminal_nombre, \'\')' : "''";
        $campoCobro = reporteColumnaExiste($conexion, 'ventas', 'monto_cobrado_tarjeta') ? 'COALESCE(v.monto_cobrado_tarjeta, \'\')' : "''";
        $campoRecargo = reporteColumnaExiste($conexion, 'ventas', 'recargo_cliente_tarjeta') ? 'COALESCE(v.recargo_cliente_tarjeta, 0)' : '0';
        $tieneUsuarioVenta = reporteColumnaExiste($conexion, 'ventas', 'id_usuario');
        $joinUsuario = $tieneUsuarioVenta ? ' LEFT JOIN usuarios u ON u.id = v.id_usuario' : '';
        $campoCajero = $tieneUsuarioVenta ? "COALESCE(NULLIF(u.nombre, ''), 'No registrado')" : "'No registrado'";
        $tienePagosVenta = reporteTablaExiste($conexion, 'pagos_venta');
        $joinPagos = $tienePagosVenta
            ? " LEFT JOIN (
                    SELECT id_venta,
                        COUNT(*) AS numero_metodos,
                        SUM(CASE WHEN metodo = 'efectivo' THEN monto ELSE 0 END) AS efectivo_aplicado,
                        SUM(CASE WHEN metodo = 'efectivo' THEN monto_recibido ELSE 0 END) AS efectivo_recibido,
                        SUM(CASE WHEN metodo = 'efectivo' THEN cambio ELSE 0 END) AS cambio_efectivo,
                        SUM(CASE WHEN metodo = 'tarjeta' THEN monto ELSE 0 END) AS tarjeta_aplicada,
                        SUM(CASE WHEN metodo = 'transferencia' THEN monto ELSE 0 END) AS transferencia_aplicada,
                        SUM(CASE WHEN metodo = 'otro' THEN monto ELSE 0 END) AS otro_aplicado,
                        SUM(CASE WHEN metodo = 'tarjeta' THEN monto_cobrado_tarjeta ELSE 0 END) AS cobrado_terminal,
                        SUM(CASE WHEN metodo = 'tarjeta' THEN recargo_cliente_tarjeta ELSE 0 END) AS recargo_terminal,
                        MAX(NULLIF(terminal_nombre, '')) AS terminal_nombre
                    FROM pagos_venta GROUP BY id_venta
                ) pg ON pg.id_venta = v.id_venta"
            : '';
        $campoMetodoReporte = $tienePagosVenta
            ? "CASE WHEN COALESCE(pg.numero_metodos, 0) > 1 THEN 'Mixto' ELSE $campoMetodo END"
            : $campoMetodo;
        $campoEfectivoAplicado = $tienePagosVenta
            ? "CASE WHEN pg.id_venta IS NOT NULL THEN COALESCE(pg.efectivo_aplicado, 0) WHEN $campoMetodo = 'efectivo' THEN v.total ELSE 0 END"
            : "CASE WHEN $campoMetodo = 'efectivo' THEN v.total ELSE 0 END";
        $campoEfectivoRecibido = $tienePagosVenta ? "CASE WHEN pg.id_venta IS NOT NULL THEN COALESCE(pg.efectivo_recibido, 0) ELSE '' END" : "''";
        $campoCambio = $tienePagosVenta ? "CASE WHEN pg.id_venta IS NOT NULL THEN COALESCE(pg.cambio_efectivo, 0) ELSE '' END" : "''";
        $campoTarjetaAplicada = $tienePagosVenta
            ? "CASE WHEN pg.id_venta IS NOT NULL THEN COALESCE(pg.tarjeta_aplicada, 0) WHEN $campoMetodo = 'tarjeta' THEN v.total ELSE 0 END"
            : "CASE WHEN $campoMetodo = 'tarjeta' THEN v.total ELSE 0 END";
        $campoTransferenciaAplicada = $tienePagosVenta
            ? "CASE WHEN pg.id_venta IS NOT NULL THEN COALESCE(pg.transferencia_aplicada, 0) WHEN $campoMetodo = 'transferencia' THEN v.total ELSE 0 END"
            : "CASE WHEN $campoMetodo = 'transferencia' THEN v.total ELSE 0 END";
        $campoOtroAplicado = $tienePagosVenta
            ? "CASE WHEN pg.id_venta IS NOT NULL THEN COALESCE(pg.otro_aplicado, 0) WHEN $campoMetodo = 'otro' THEN v.total ELSE 0 END"
            : "CASE WHEN $campoMetodo = 'otro' THEN v.total ELSE 0 END";
        $campoCobroReporte = $tienePagosVenta
            ? "CASE WHEN pg.id_venta IS NOT NULL THEN COALESCE(pg.cobrado_terminal, 0) ELSE $campoCobro END"
            : $campoCobro;
        $campoRecargoReporte = $tienePagosVenta
            ? "CASE WHEN pg.id_venta IS NOT NULL THEN COALESCE(pg.recargo_terminal, 0) ELSE $campoRecargo END"
            : $campoRecargo;
        $campoTerminalReporte = $tienePagosVenta
            ? "CASE WHEN pg.id_venta IS NOT NULL THEN COALESCE(pg.terminal_nombre, '') ELSE $campoTerminal END"
            : $campoTerminal;
        $campoNota = "''";
        foreach (['notas', 'nota_venta', 'observaciones', 'nota', 'comentario'] as $candidata) {
            if (reporteColumnaExiste($conexion, 'ventas', $candidata)) {
                $campoNota = 'COALESCE(v.' . $candidata . ', \'\')';
                break;
            }
        }
        if ($tipo === 'ventas') {
            $encabezados = ['Venta', 'Fecha y hora', 'Cajero', 'Método de pago', 'Total productos', 'Efectivo aplicado', 'Efectivo recibido', 'Cambio', 'Tarjeta aplicada', 'Transferencia', 'Otro', 'Cobrado en terminal', 'Recargo cliente', 'Terminal', 'Nota'];
            $sql = "SELECT v.id_venta AS `Venta`, v.fecha_venta AS `Fecha y hora`, $campoCajero AS `Cajero`, $campoMetodoReporte AS `Método de pago`, v.total AS `Total productos`, $campoEfectivoAplicado AS `Efectivo aplicado`, $campoEfectivoRecibido AS `Efectivo recibido`, $campoCambio AS `Cambio`, $campoTarjetaAplicada AS `Tarjeta aplicada`, $campoTransferenciaAplicada AS `Transferencia`, $campoOtroAplicado AS `Otro`, $campoCobroReporte AS `Cobrado en terminal`, $campoRecargoReporte AS `Recargo cliente`, $campoTerminalReporte AS `Terminal`, $campoNota AS `Nota`
                    FROM ventas v{$joinUsuario}{$joinPagos} WHERE v.fecha_venta >= '$fechaDesde' AND v.fecha_venta <= '$fechaHasta' ORDER BY v.fecha_venta ASC, v.id_venta ASC";
        } else {
            $encabezados = ['Fecha', 'Ventas registradas', 'Total productos', 'Efectivo aplicado', 'Tarjeta aplicada', 'Transferencia', 'Otro', 'Fiado', 'Cobrado en terminal', 'Recargo terminal'];
            $campoFiado = "CASE WHEN $campoMetodo = 'fiado' THEN v.total ELSE 0 END";
            $sql = "SELECT DATE(v.fecha_venta) AS `Fecha`, COUNT(*) AS `Ventas registradas`, COALESCE(SUM(v.total), 0) AS `Total productos`, COALESCE(SUM($campoEfectivoAplicado), 0) AS `Efectivo aplicado`, COALESCE(SUM($campoTarjetaAplicada), 0) AS `Tarjeta aplicada`, COALESCE(SUM($campoTransferenciaAplicada), 0) AS `Transferencia`, COALESCE(SUM($campoOtroAplicado), 0) AS `Otro`, COALESCE(SUM($campoFiado), 0) AS `Fiado`, COALESCE(SUM($campoCobroReporte), 0) AS `Cobrado en terminal`, COALESCE(SUM($campoRecargoReporte), 0) AS `Recargo terminal`
                    FROM ventas v{$joinPagos} WHERE v.fecha_venta >= '$fechaDesde' AND v.fecha_venta <= '$fechaHasta' GROUP BY DATE(v.fecha_venta) ORDER BY DATE(v.fecha_venta) ASC";
        }
        $resultado = mysqli_query($conexion, $sql);
    } elseif ($tipo === 'cortes') {
        $campoFechaOperativa = reporteColumnaExiste($conexion, 'cortes_caja', 'fecha_corte') ? 'c.fecha_corte' : "''";
        $campoInyeccion = reporteColumnaExiste($conexion, 'cortes_caja', 'dinero_personal_inyeccion') ? 'COALESCE(c.dinero_personal_inyeccion, 0)' : '0';
        $encabezados = ['Corte', 'Fecha y hora', 'Día operativo', 'Cajero', 'Fondo inicial', 'Ventas efectivo', 'Gastos', 'Inyección', 'Contado físico', 'Diferencia', 'Observaciones'];
        $sql = "SELECT c.id AS `Corte`, c.fecha_cierre AS `Fecha y hora`, $campoFechaOperativa AS `Día operativo`, COALESCE(NULLIF(u.nombre, ''), 'Usuario no disponible') AS `Cajero`, c.monto_inicial AS `Fondo inicial`, c.ventas_totales AS `Ventas efectivo`, c.gastos_dia AS `Gastos`, $campoInyeccion AS `Inyección`, c.monto_real_contado AS `Contado físico`, c.diferencia AS `Diferencia`, COALESCE(c.observaciones, '') AS `Observaciones`
                FROM cortes_caja c LEFT JOIN usuarios u ON u.id = c.id_usuario WHERE c.fecha_cierre >= '$fechaDesde' AND c.fecha_cierre <= '$fechaHasta' ORDER BY c.fecha_cierre ASC, c.id ASC";
        $resultado = mysqli_query($conexion, $sql);
    } elseif ($tipo === 'inventario') {
        $campoActivo = reporteColumnaExiste($conexion, 'productos', 'activo') ? 'COALESCE(p.activo, 1)' : '1';
        $encabezados = ['ID', 'Código de barras', 'Producto', 'Marca / proveedor', 'Unidad', 'Stock', 'Costo', 'Precio de venta', 'Categoría', 'Estado'];
        $sql = "SELECT p.id AS `ID`, COALESCE(p.codigo_barras, '') AS `Código de barras`, p.nombre AS `Producto`, COALESCE(p.marca, '') AS `Marca / proveedor`, COALESCE(p.unidad_medida, 'pza') AS `Unidad`, p.stock AS `Stock`, p.precio_compra AS `Costo`, p.precio_venta AS `Precio de venta`, COALESCE(p.categoria, '') AS `Categoría`, CASE WHEN $campoActivo = 1 THEN 'Activo' ELSE 'Pausado' END AS `Estado` FROM productos p ORDER BY p.nombre ASC";
        $resultado = mysqli_query($conexion, $sql);
    } elseif ($tipo === 'libreta') {
        $campoActivo = reporteColumnaExiste($conexion, 'clientes', 'activo') ? 'COALESCE(c.activo, 1)' : '1';
        $encabezados = ['Cliente', 'Nombre', 'Teléfono', 'Límite de crédito', 'Saldo actual', 'Estado'];
        $sql = "SELECT c.id_cliente AS `Cliente`, c.nombre_completo AS `Nombre`, COALESCE(c.telefono, '') AS `Teléfono`, COALESCE(c.limite_credito, 0) AS `Límite de crédito`, COALESCE(c.saldo_actual, 0) AS `Saldo actual`, CASE WHEN $campoActivo = 1 THEN 'Activo' ELSE 'Archivado' END AS `Estado` FROM clientes c ORDER BY c.nombre_completo ASC";
        $resultado = mysqli_query($conexion, $sql);
    } else {
        foreach (['compras', 'compra_detalles', 'movimientos_inventario'] as $tabla) {
            if (!reporteTablaExiste($conexion, $tabla)) reporteRedirigirError('estructura_pendiente');
        }
        $encabezados = ['Fecha y hora', 'Tipo', 'Referencia', 'Proveedor', 'Producto', 'Cantidad', 'Costo unitario', 'Importe', 'Folio / motivo', 'Notas'];
        $sql = "SELECT c.fecha_compra AS `Fecha y hora`, 'Compra' AS `Tipo`, c.id AS `Referencia`, COALESCE(pv.nombre, '') AS `Proveedor`, p.nombre AS `Producto`, cd.cantidad_recibida AS `Cantidad`, cd.costo_unitario AS `Costo unitario`, cd.subtotal AS `Importe`, COALESCE(c.folio, '') AS `Folio / motivo`, COALESCE(c.notas, '') AS `Notas`
                FROM compras c JOIN compra_detalles cd ON cd.id_compra = c.id JOIN productos p ON p.id = cd.id_producto LEFT JOIN proveedores pv ON pv.id = c.id_proveedor
                WHERE c.fecha_compra >= '$fechaDesde' AND c.fecha_compra <= '$fechaHasta'
                UNION ALL
                SELECT m.fecha_movimiento AS `Fecha y hora`, 'Merma' AS `Tipo`, m.id AS `Referencia`, '' AS `Proveedor`, p.nombre AS `Producto`, ABS(m.cantidad) AS `Cantidad`, m.costo_unitario AS `Costo unitario`, ABS(m.cantidad) * m.costo_unitario AS `Importe`, COALESCE(m.motivo, '') AS `Folio / motivo`, '' AS `Notas`
                FROM movimientos_inventario m JOIN productos p ON p.id = m.id_producto WHERE m.tipo = 'merma' AND m.fecha_movimiento >= '$fechaDesde' AND m.fecha_movimiento <= '$fechaHasta'
                ORDER BY `Fecha y hora` ASC, `Referencia` ASC";
        $resultado = mysqli_query($conexion, $sql);
    }

    if (!$resultado) throw new RuntimeException(mysqli_error($conexion));
    nuviRegistrarAuditoria($conexion, 'reporte_exportado', 'reporte', null, 'Reporte exportado: ' . $tipo, ['desde' => $desde, 'hasta' => $hasta]);
    reporteDescargarCsv($nombre, $encabezados, $resultado);
} catch (Throwable $error) {
    error_log('exportar_reporte.php: ' . $error->getMessage());
    reporteRedirigirError('error_reporte');
}
