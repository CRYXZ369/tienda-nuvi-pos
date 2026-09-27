<?php
include("auth.php");
tienePermiso(['admin']);
include("conexion.php");
require_once("app_header.php");
require_once("comisiones_terminal.php");
require_once("pagos_venta_utils.php");

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    die('Sin conexión a base de datos');
}

$tieneMetodoPago = false;
$resMetodoPago = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE 'metodo_pago'");
if ($resMetodoPago && mysqli_num_rows($resMetodoPago) > 0) {
    $tieneMetodoPago = true;
}

$tienePagosVenta = nuviPagosVentaTablaExiste($conexion);
if ($tieneMetodoPago && $tienePagosVenta) {
    $exprMetodo = static function (string $metodo, string $fallback): string {
        return "COALESCE(SUM(CASE
            WHEN EXISTS (SELECT 1 FROM pagos_venta pvx WHERE pvx.id_venta = ventas.id_venta)
                THEN COALESCE((SELECT SUM(pv.monto) FROM pagos_venta pv WHERE pv.id_venta = ventas.id_venta AND pv.metodo = '$metodo'), 0)
            WHEN $fallback THEN total ELSE 0 END), 0)";
    };
    $exprEfectivo = $exprMetodo('efectivo', "metodo_pago = 'efectivo' OR metodo_pago IS NULL OR metodo_pago = ''");
    $exprTarjeta = $exprMetodo('tarjeta', "metodo_pago = 'tarjeta'");
    $exprTransferencia = $exprMetodo('transferencia', "metodo_pago = 'transferencia'");
    $exprFiado = $exprMetodo('fiado', "metodo_pago = 'fiado'");
    $exprOtro = $exprMetodo('otro', "metodo_pago = 'otro'");
} else {
    $exprEfectivo = $tieneMetodoPago
        ? "COALESCE(SUM(CASE WHEN metodo_pago = 'efectivo' OR metodo_pago IS NULL OR metodo_pago = '' THEN total ELSE 0 END), 0)"
        : "COALESCE(SUM(total), 0)";
    $exprTarjeta = $tieneMetodoPago ? "COALESCE(SUM(CASE WHEN metodo_pago = 'tarjeta' THEN total ELSE 0 END), 0)" : "0";
    $exprTransferencia = $tieneMetodoPago ? "COALESCE(SUM(CASE WHEN metodo_pago = 'transferencia' THEN total ELSE 0 END), 0)" : "0";
    $exprFiado = $tieneMetodoPago ? "COALESCE(SUM(CASE WHEN metodo_pago = 'fiado' THEN total ELSE 0 END), 0)" : "0";
    $exprOtro = $tieneMetodoPago ? "COALESCE(SUM(CASE WHEN metodo_pago = 'otro' THEN total ELSE 0 END), 0)" : "0";
}
$tieneMontoCobradoTarjeta = nuviColumnaExiste($conexion, 'ventas', 'monto_cobrado_tarjeta');
$tieneRecargoClienteTarjeta = nuviColumnaExiste($conexion, 'ventas', 'recargo_cliente_tarjeta');
$exprCobradoTerminal = ($tieneMetodoPago && $tieneMontoCobradoTarjeta)
    ? ($tienePagosVenta
        ? "COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM pagos_venta pvx WHERE pvx.id_venta = ventas.id_venta) THEN COALESCE((SELECT SUM(CASE WHEN pv.metodo = 'tarjeta' THEN COALESCE(pv.monto_cobrado_tarjeta, pv.monto) ELSE 0 END) FROM pagos_venta pv WHERE pv.id_venta = ventas.id_venta), 0) WHEN metodo_pago = 'tarjeta' THEN COALESCE(monto_cobrado_tarjeta, total) ELSE 0 END), 0)"
        : "COALESCE(SUM(CASE WHEN metodo_pago = 'tarjeta' THEN COALESCE(monto_cobrado_tarjeta, total) ELSE 0 END), 0)")
    : $exprTarjeta;
$exprRecargoClienteTarjeta = ($tieneMetodoPago && $tieneRecargoClienteTarjeta)
    ? ($tienePagosVenta
        ? "COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM pagos_venta pvx WHERE pvx.id_venta = ventas.id_venta) THEN COALESCE((SELECT SUM(CASE WHEN pv.metodo = 'tarjeta' THEN pv.recargo_cliente_tarjeta ELSE 0 END) FROM pagos_venta pv WHERE pv.id_venta = ventas.id_venta), 0) WHEN metodo_pago = 'tarjeta' THEN COALESCE(recargo_cliente_tarjeta, 0) ELSE 0 END), 0)"
        : "COALESCE(SUM(CASE WHEN metodo_pago = 'tarjeta' THEN COALESCE(recargo_cliente_tarjeta, 0) ELSE 0 END), 0)")
    : "0";

function nuviFechaOperativaCorte(string $fechaCierre, ?string $fechaCorte = null): string
{
    try {
        $fechaOperativaGuardada = trim((string) $fechaCorte);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaOperativaGuardada)) {
            $fecha = DateTime::createFromFormat('!Y-m-d', $fechaOperativaGuardada);
            if ($fecha && $fecha->format('Y-m-d') === $fechaOperativaGuardada) {
                return $fechaOperativaGuardada;
            }
        }

        $fecha = new DateTime($fechaCierre);
        if ((int) $fecha->format('H') < 6) {
            $fecha->modify('-1 day');
        }
        return $fecha->format('Y-m-d');
    } catch (Throwable $error) {
        return date('Y-m-d', strtotime($fechaCierre));
    }
}

// Si se llega desde Guardar corte, se prioriza el ID recibido para que el
// encargado pueda verificar exactamente ese corte. Al entrar normalmente,
// se muestra el último corte real registrado.
$idCorteSolicitado = filter_input(INPUT_GET, 'corte', FILTER_VALIDATE_INT);
$idCorteSolicitado = $idCorteSolicitado && $idCorteSolicitado > 0 ? (int) $idCorteSolicitado : 0;
$corteDestacado = null;

if ($idCorteSolicitado > 0) {
    $sqlCorteSolicitado = "SELECT c.*, COALESCE(NULLIF(u.nombre, ''), 'Usuario no disponible') AS cajero
        FROM cortes_caja c
        LEFT JOIN usuarios u ON c.id_usuario = u.id
        WHERE c.id = $idCorteSolicitado
        LIMIT 1";
    $resCorteSolicitado = mysqli_query($conexion, $sqlCorteSolicitado);
    $corteDestacado = $resCorteSolicitado ? mysqli_fetch_assoc($resCorteSolicitado) : null;
}

if (!$corteDestacado) {
    $sqlUltimoCorte = "SELECT c.*, COALESCE(NULLIF(u.nombre, ''), 'Usuario no disponible') AS cajero
        FROM cortes_caja c
        LEFT JOIN usuarios u ON c.id_usuario = u.id
        ORDER BY c.fecha_cierre DESC, c.id DESC
        LIMIT 1";
    $resUltimoCorte = mysqli_query($conexion, $sqlUltimoCorte);
    $corteDestacado = $resUltimoCorte ? mysqli_fetch_assoc($resUltimoCorte) : null;
}

$idCorteDestacado = (int) ($corteDestacado['id'] ?? 0);
$filtroHistorial = $idCorteDestacado > 0 ? "WHERE c.id <> $idCorteDestacado" : '';
$sql = "SELECT c.*, COALESCE(NULLIF(u.nombre, ''), 'Usuario no disponible') AS cajero
        FROM cortes_caja c
        LEFT JOIN usuarios u ON c.id_usuario = u.id
        $filtroHistorial
        ORDER BY c.fecha_cierre DESC, c.id DESC";
$resultado = mysqli_query($conexion, $sql);

// Antes se hacía una consulta de métodos por cada fila del historial (N+1).
// Agruparlas una vez evita que el historial se vuelva lento al crecer.
$metodosPorFecha = [];
$metodosPorCorteSql = "SELECT
    DATE(DATE_SUB(fecha_venta, INTERVAL 6 HOUR)) AS fecha_operativa,
    $exprEfectivo AS efectivo,
    $exprTarjeta AS tarjeta,
    $exprCobradoTerminal AS cobrado_terminal,
    $exprRecargoClienteTarjeta AS recargo_cliente_tarjeta,
    $exprTransferencia AS transferencia,
    $exprFiado AS fiado,
    $exprOtro AS otro
    FROM ventas
    WHERE fecha_venta IS NOT NULL
    GROUP BY DATE(DATE_SUB(fecha_venta, INTERVAL 6 HOUR))";
$metodosPorCorteRes = mysqli_query($conexion, $metodosPorCorteSql);
if ($metodosPorCorteRes) {
    while ($filaMetodo = mysqli_fetch_assoc($metodosPorCorteRes)) {
        $metodosPorFecha[$filaMetodo['fecha_operativa']] = $filaMetodo;
    }
}

$statsSql = "SELECT
    COUNT(*) AS total_cortes,
    COALESCE(SUM(ventas_totales), 0) AS ventas_acumuladas,
    COALESCE(SUM(monto_real_contado), 0) AS efectivo_contado,
    COALESCE(AVG(diferencia), 0) AS diferencia_promedio,
    COALESCE(SUM(CASE WHEN diferencia < 0 THEN 1 ELSE 0 END), 0) AS cortes_con_faltante
    FROM cortes_caja
    WHERE fecha_cierre >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$statsRes = mysqli_query($conexion, $statsSql);
$stats = $statsRes ? mysqli_fetch_assoc($statsRes) : [
    'total_cortes' => 0,
    'ventas_acumuladas' => 0,
    'efectivo_contado' => 0,
    'diferencia_promedio' => 0,
    'cortes_con_faltante' => 0
];

$trendSql = "SELECT
    DATE(fecha_cierre) AS fecha,
    COALESCE(SUM(ventas_totales), 0) AS ventas,
    COALESCE(SUM(monto_real_contado), 0) AS contado,
    COALESCE(SUM(diferencia), 0) AS diferencia
    FROM cortes_caja
    WHERE fecha_cierre >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(fecha_cierre)
    ORDER BY DATE(fecha_cierre) ASC";

$trendRes = mysqli_query($conexion, $trendSql);
$trendLabels = [];
$trendVentas = [];
$trendContado = [];
$trendDif = [];

if ($trendRes) {
    while ($row = mysqli_fetch_assoc($trendRes)) {
        $trendLabels[] = date('d/m', strtotime($row['fecha']));
        $trendVentas[] = (float)($row['ventas'] ?? 0);
        $trendContado[] = (float)($row['contado'] ?? 0);
        $trendDif[] = (float)($row['diferencia'] ?? 0);
    }
}

$metodosSql = "SELECT
    $exprEfectivo AS efectivo,
    $exprTarjeta AS tarjeta,
    $exprCobradoTerminal AS cobrado_terminal,
    $exprRecargoClienteTarjeta AS recargo_cliente_tarjeta,
    $exprTransferencia AS transferencia,
    $exprFiado AS fiado,
    $exprOtro AS otro
    FROM ventas
    WHERE fecha_venta >= DATE_SUB(NOW(), INTERVAL 14 DAY)";

$metodosRes = mysqli_query($conexion, $metodosSql);
$metodosStats = $metodosRes ? mysqli_fetch_assoc($metodosRes) : [
    'efectivo' => 0,
    'tarjeta' => 0,
    'cobrado_terminal' => 0,
    'recargo_cliente_tarjeta' => 0,
    'transferencia' => 0,
    'fiado' => 0,
    'otro' => 0
];

// Valor de la mercancía disponible. Se calcula aparte de ventas y cortes para
// que sea una referencia patrimonial, no dinero contado en caja.
$tieneActivoProducto = nuviColumnaExiste($conexion, 'productos', 'activo');
$filtroInventarioActivo = $tieneActivoProducto ? '(activo IS NULL OR activo = 1)' : '1 = 1';
$inventarioSql = "SELECT
    COUNT(*) AS productos_activos,
    COALESCE(SUM(GREATEST(COALESCE(stock, 0), 0) * COALESCE(precio_compra, 0)), 0) AS valor_costo,
    COALESCE(SUM(GREATEST(COALESCE(stock, 0), 0) * COALESCE(precio_venta, 0)), 0) AS valor_venta
    FROM productos
    WHERE $filtroInventarioActivo";
$inventarioRes = mysqli_query($conexion, $inventarioSql);
$inventario = $inventarioRes ? mysqli_fetch_assoc($inventarioRes) : [
    'productos_activos' => 0,
    'valor_costo' => 0,
    'valor_venta' => 0,
];

$tieneTerminalNombre = nuviColumnaExiste($conexion, 'ventas', 'terminal_nombre');
$terminalesResumen = [];
if ($tieneMetodoPago && $tieneTerminalNombre) {
    $terminalesSql = "SELECT
            COALESCE(NULLIF(terminal_nombre, ''), 'Sin asignar') AS terminal,
            COUNT(*) AS operaciones,
            COALESCE(SUM(COALESCE(monto_cobrado_tarjeta, total)), 0) AS cobrado,
            COALESCE(SUM(COALESCE(recargo_cliente_tarjeta, 0)), 0) AS recargo
        FROM ventas
        WHERE metodo_pago = 'tarjeta'
          AND fecha_venta >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY COALESCE(NULLIF(terminal_nombre, ''), 'Sin asignar')
        ORDER BY cobrado DESC";
    $terminalesRes = mysqli_query($conexion, $terminalesSql);
    if ($terminalesRes) {
        while ($terminal = mysqli_fetch_assoc($terminalesRes)) {
            $terminalesResumen[] = $terminal;
        }
    }
}

$fechaBase = new DateTime();
$mesActual = $fechaBase->format('Y-m-01');
$siguienteMes = (clone $fechaBase)->modify('first day of next month')->format('Y-m-01');
$mensualSql = "SELECT
    COUNT(*) AS total_cortes,
    COALESCE(SUM(ventas_totales), 0) AS ventas_acumuladas,
    COALESCE(AVG(ventas_totales), 0) AS ticket_promedio,
    COALESCE(SUM(CASE WHEN diferencia < 0 THEN 1 ELSE 0 END), 0) AS cortes_con_faltante,
    COALESCE(SUM(CASE WHEN diferencia >= 0 THEN 1 ELSE 0 END), 0) AS cortes_sin_faltante
    FROM cortes_caja
    WHERE fecha_cierre >= '$mesActual' AND fecha_cierre < '$siguienteMes'";
$mensualRes = mysqli_query($conexion, $mensualSql);
$mensual = $mensualRes ? mysqli_fetch_assoc($mensualRes) : [
    'total_cortes' => 0,
    'ventas_acumuladas' => 0,
    'ticket_promedio' => 0,
    'cortes_con_faltante' => 0,
    'cortes_sin_faltante' => 0
];

$ventasMesSql = "SELECT DATE(fecha_venta) AS fecha, COALESCE(SUM(total),0) AS total
    FROM ventas
    WHERE fecha_venta >= '$mesActual' AND fecha_venta < '$siguienteMes'
    GROUP BY DATE(fecha_venta)
    ORDER BY DATE(fecha_venta) ASC";
$ventasMesRes = mysqli_query($conexion, $ventasMesSql);
$ventasMesLabels = [];
$ventasMesTotales = [];
if ($ventasMesRes) {
    while ($row = mysqli_fetch_assoc($ventasMesRes)) {
        $ventasMesLabels[] = date('d', strtotime($row['fecha']));
        $ventasMesTotales[] = (float)$row['total'];
    }
}

$metodosMesSql = "SELECT
    $exprEfectivo AS efectivo,
    $exprTarjeta AS tarjeta,
    $exprCobradoTerminal AS cobrado_terminal,
    $exprRecargoClienteTarjeta AS recargo_cliente_tarjeta,
    $exprTransferencia AS transferencia,
    $exprFiado AS fiado,
    $exprOtro AS otro
    FROM ventas
    WHERE fecha_venta >= '$mesActual' AND fecha_venta < '$siguienteMes'";
$metodosMesRes = mysqli_query($conexion, $metodosMesSql);
$metodosMes = $metodosMesRes ? mysqli_fetch_assoc($metodosMesRes) : [
    'efectivo' => 0,
    'tarjeta' => 0,
    'cobrado_terminal' => 0,
    'recargo_cliente_tarjeta' => 0,
    'transferencia' => 0,
    'fiado' => 0,
    'otro' => 0
];

$topCategoriasSql = "SELECT COALESCE(p.categoria, 'Sin categoría') AS categoria, COUNT(*) AS total
    FROM ventas v
    JOIN detalle_ventas dv ON dv.id_venta = v.id_venta
    JOIN productos p ON p.id = dv.id_producto
    WHERE v.fecha_venta >= '$mesActual' AND v.fecha_venta < '$siguienteMes'
    GROUP BY COALESCE(p.categoria, 'Sin categoría')
    ORDER BY total DESC
    LIMIT 6";
$topCategoriasRes = mysqli_query($conexion, $topCategoriasSql);
$topCategoriasLabels = [];
$topCategoriasTotales = [];
if ($topCategoriasRes) {
    while ($row = mysqli_fetch_assoc($topCategoriasRes)) {
        $topCategoriasLabels[] = $row['categoria'];
        $topCategoriasTotales[] = (int)$row['total'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Cortes | Nuvi</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f8; padding: 10px; margin: 0; }
        .contenedor { max-width: 1200px; margin: auto; background: white; padding: 20px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }

        /* Cabecera Responsiva */
        .header-flex { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }

        /* Contenedor de tabla con scroll horizontal para móviles */
        .tabla-responsiva { width: 100%; overflow-x: auto; margin-top: 20px; -webkit-overflow-scrolling: touch; }

        table { width: 100%; border-collapse: collapse; min-width: 1100px; } /* Evita que se amontone en pantallas chicas */
        th, td { padding: 12px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { background: #f8fafc; color: #64748b; font-size: 0.75rem; text-transform: uppercase; }

        .btn-edit { text-decoration: none; background: #f3f4f6; padding: 6px 12px; border-radius: 6px; color: #374151; font-size: 0.8rem; border: 1px solid #d1d5db; display: inline-block; margin-bottom: 5px; }
        .acciones-historial { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .btn-terminales { text-decoration:none; background:#1d4ed8; color:#fff; padding:10px 14px; border-radius:9px; font-weight:700; box-shadow:0 4px 10px rgba(29,78,216,.18); }
        .btn-terminales:hover { background:#1e40af; }
        .btn-reportes { text-decoration:none; background:#0f766e; color:#fff; padding:10px 14px; border-radius:9px; font-weight:700; box-shadow:0 4px 10px rgba(15,118,110,.18); }
        .btn-reportes:hover { background:#115e59; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; background: #e2e8f0; display: block; width: fit-content; }

        .editable { background: #fffdf2; cursor: pointer; min-width: 60px; }
        .diferencia-positiva { color: #10b981; font-weight: bold; }
        .diferencia-negativa { color: #ef4444; font-weight: bold; }
        .metodos-resumen { font-size: 0.8rem; line-height: 1.5; color: #475569; }
        .metodos-resumen strong { color: #0f172a; }

        .aviso-corte-guardado {
            margin: 14px 0;
            padding: 12px 14px;
            border-radius: 10px;
            background: #ecfdf5;
            border: 1px solid #86efac;
            color: #166534;
            font-weight: 700;
        }

        .corte-destacado {
            scroll-margin-top: 14px;
            margin: 14px 0 22px;
            padding: 18px;
            border: 1px solid #bfdbfe;
            border-left: 5px solid #2563eb;
            border-radius: 14px;
            background: linear-gradient(135deg, #eff6ff, #ffffff);
            box-shadow: 0 8px 20px rgba(37, 99, 235, .10);
        }

        .corte-destacado-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .corte-destacado h3 { margin: 0; color: #1d4ed8; }
        .corte-destacado-subtitulo { margin: 5px 0 0; color: #475569; font-size: .9rem; }
        .corte-destacado-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
            gap: 10px;
        }

        .dato-corte {
            padding: 11px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .76);
            border: 1px solid #dbeafe;
        }

        .dato-corte span { display: block; color: #64748b; font-size: .78rem; margin-bottom: 4px; }
        .dato-corte strong { color: #0f172a; font-size: 1rem; }
        .estado-corte { padding: 10px 12px; border-radius: 10px; font-weight: 800; }
        .estado-corte.cuadrado { background: #dcfce7; color: #166534; }
        .estado-corte.sobrante { background: #dbeafe; color: #1d4ed8; }
        .estado-corte.faltante { background: #fef2f2; color: #b91c1c; }
        .nota-corte { margin: 14px 0 0; color: #334155; font-size: .9rem; white-space: pre-wrap; }
        .sin-cortes { margin: 18px 0; padding: 14px; border-radius: 10px; background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

        html.nuvi-tema-oscuro .corte-destacado {
            background: linear-gradient(135deg, #172554, #172033) !important;
            border-color: #3b82f6 !important;
        }
        html.nuvi-tema-oscuro .aviso-corte-guardado { background: #123326 !important; border-color: #26834a !important; color: #bbf7d0 !important; }
        html.nuvi-tema-oscuro .corte-destacado h3 { color: #bfdbfe !important; }
        html.nuvi-tema-oscuro .corte-destacado-subtitulo,
        html.nuvi-tema-oscuro .nota-corte,
        html.nuvi-tema-oscuro .dato-corte span { color: #cbd5e1 !important; }
        html.nuvi-tema-oscuro .dato-corte { background: #1e293b !important; border-color: #334155 !important; }
        html.nuvi-tema-oscuro .dato-corte strong { color: #f8fafc !important; }
        html.nuvi-tema-oscuro .estado-corte.cuadrado { background: #143c2c !important; color: #bbf7d0 !important; }
        html.nuvi-tema-oscuro .estado-corte.sobrante { background: #1e3a5f !important; color: #bfdbfe !important; }
        html.nuvi-tema-oscuro .estado-corte.faltante { background: #4c1d27 !important; color: #fecaca !important; }
        html.nuvi-tema-oscuro .sin-cortes { background: #1e293b !important; border-color: #334155 !important; color: #cbd5e1 !important; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin: 8px 0 18px;
        }

        .kpi-card {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #fff;
            padding: 14px;
            border-radius: 12px;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.25);
            animation: slideIn .45s ease both;
        }

        .kpi-card:nth-child(2) { animation-delay: .06s; }
        .kpi-card:nth-child(3) { animation-delay: .12s; }
        .kpi-card:nth-child(4) { animation-delay: .18s; }
        .kpi-card:nth-child(5) { animation-delay: .24s; }

        .kpi-label { font-size: .8rem; opacity: .82; margin-bottom: 8px; }
        .kpi-value { font-size: 1.4rem; font-weight: 800; letter-spacing: .3px; }

        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            margin: 8px 0 22px;
        }

        .chart-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
            animation: fadeUp .42s ease both;
        }

        .chart-title {
            margin: 0 0 10px;
            font-size: .92rem;
            color: #0f172a;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 600px) {
            h2 { font-size: 1.2rem; }
            .contenedor { padding: 15px; }
        }

        @media (max-width: 900px) {
            .charts-grid { grid-template-columns: 1fr; }
        }
    </style>
    <?php renderAppHeaderStyles(); ?>
</head>
<body>
<?php renderAppHeader('historial_cortes', true); ?>

<div class="contenedor">
    <div class="header-flex">
        <h2>📋 Historial de Cortes</h2>
        <div class="acciones-historial">
            <a class="btn-reportes" href="reportes.php">📤 Reportes</a>
            <a class="btn-terminales" href="configurar_terminal.php">💳 Configurar terminales</a>
        </div>
    </div>

    <?php
        $mensajeHistorial = (string) ($_GET['msj'] ?? '');
        $mensajeExito = $mensajeHistorial === 'ok'
            ? 'Corte guardado correctamente. Revisa aquí mismo el resultado y la diferencia.'
            : ($mensajeHistorial === 'corte_actualizado'
                ? 'Cambios del corte guardados correctamente.'
                : '');
    ?>
    <?php if ($mensajeExito !== ''): ?>
        <div class="aviso-corte-guardado" role="status">✓ <?php echo $mensajeExito; ?></div>
    <?php endif; ?>

    <?php if ($corteDestacado): ?>
        <?php
            $diferenciaDestacada = (float) ($corteDestacado['diferencia'] ?? 0);
            if ($diferenciaDestacada < -0.004) {
                $estadoDiferencia = 'faltante';
                $textoDiferencia = 'Faltante de $' . number_format(abs($diferenciaDestacada), 2);
            } elseif ($diferenciaDestacada > 0.004) {
                $estadoDiferencia = 'sobrante';
                $textoDiferencia = 'Sobrante de $' . number_format($diferenciaDestacada, 2);
            } else {
                $estadoDiferencia = 'cuadrado';
                $textoDiferencia = 'Sin diferencia: caja cuadrada';
            }
            $fechaOperativaDestacada = nuviFechaOperativaCorte(
                (string) $corteDestacado['fecha_cierre'],
                $corteDestacado['fecha_corte'] ?? null
            );
            $metodosDestacados = $metodosPorFecha[$fechaOperativaDestacada] ?? [
                'efectivo' => 0,
                'tarjeta' => 0,
                'cobrado_terminal' => 0,
                'transferencia' => 0,
                'fiado' => 0,
            ];
            $esperadoDestacado = (float) $corteDestacado['monto_inicial']
                + (float) $corteDestacado['ventas_totales']
                + (float) $corteDestacado['dinero_personal_inyeccion']
                - (float) $corteDestacado['gastos_dia'];
        ?>
        <section id="ultimo-corte" class="corte-destacado" aria-labelledby="titulo-ultimo-corte">
            <div class="corte-destacado-head">
                <div>
                    <h3 id="titulo-ultimo-corte">✓ Último corte realizado</h3>
                    <p class="corte-destacado-subtitulo">
                        <?php echo date('d/m/Y H:i', strtotime((string) $corteDestacado['fecha_cierre'])); ?>
                        · Cajero: <?php echo htmlspecialchars((string) $corteDestacado['cajero']); ?>
                        · Día operativo: <?php echo date('d/m/Y', strtotime($fechaOperativaDestacada)); ?>
                    </p>
                </div>
                <a href="corte_caja.php?editar_id=<?php echo (int) $corteDestacado['id']; ?>" class="btn-edit">✏️ Ver / editar corte</a>
            </div>
            <div class="corte-destacado-grid">
                <div class="dato-corte"><span>Fondo inicial</span><strong>$<?php echo number_format((float) $corteDestacado['monto_inicial'], 2); ?></strong></div>
                <div class="dato-corte"><span>Ventas en efectivo</span><strong>$<?php echo number_format((float) $corteDestacado['ventas_totales'], 2); ?></strong></div>
                <div class="dato-corte"><span>Gastos</span><strong>$<?php echo number_format((float) $corteDestacado['gastos_dia'], 2); ?></strong></div>
                <div class="dato-corte"><span>Esperado en caja</span><strong>$<?php echo number_format($esperadoDestacado, 2); ?></strong></div>
                <div class="dato-corte"><span>Contado físicamente</span><strong>$<?php echo number_format((float) $corteDestacado['monto_real_contado'], 2); ?></strong></div>
                <div class="estado-corte <?php echo $estadoDiferencia; ?>"><?php echo $textoDiferencia; ?></div>
            </div>
            <p class="nota-corte"><strong>Desglose del día:</strong> Efectivo $<?php echo number_format((float) ($metodosDestacados['efectivo'] ?? 0), 2); ?> · Tarjeta $<?php echo number_format((float) ($metodosDestacados['tarjeta'] ?? 0), 2); ?> · Terminal $<?php echo number_format((float) ($metodosDestacados['cobrado_terminal'] ?? 0), 2); ?> · Transferencia $<?php echo number_format((float) ($metodosDestacados['transferencia'] ?? 0), 2); ?> · Fiado $<?php echo number_format((float) ($metodosDestacados['fiado'] ?? 0), 2); ?></p>
            <?php if (trim((string) $corteDestacado['observaciones']) !== ''): ?>
                <p class="nota-corte"><strong>Notas:</strong> <?php echo htmlspecialchars((string) $corteDestacado['observaciones']); ?></p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <div id="ultimo-corte" class="sin-cortes">Aún no hay cortes guardados. Cuando generes el primero aparecerá aquí para revisarlo de inmediato.</div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="kpi-card">
            <div class="kpi-label">Cortes (30 días)</div>
            <div class="kpi-value" data-kpi="<?php echo (int)($stats['total_cortes'] ?? 0); ?>">0</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Ventas Acumuladas</div>
            <div class="kpi-value" data-kpi="<?php echo (float)($stats['ventas_acumuladas'] ?? 0); ?>">$0</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Efectivo Contado</div>
            <div class="kpi-value" data-kpi="<?php echo (float)($stats['efectivo_contado'] ?? 0); ?>">$0</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Diferencia Promedio</div>
            <div class="kpi-value" data-kpi="<?php echo (float)($stats['diferencia_promedio'] ?? 0); ?>">$0</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Cortes con Faltante</div>
            <div class="kpi-value" data-kpi="<?php echo (int)($stats['cortes_con_faltante'] ?? 0); ?>">0</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Cobrado en terminal (14 días)</div>
            <div class="kpi-value" data-kpi="<?php echo (float)($metodosStats['cobrado_terminal'] ?? 0); ?>">$0</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Comisión pagada por clientes (14 días)</div>
            <div class="kpi-value" data-kpi="<?php echo (float)($metodosStats['recargo_cliente_tarjeta'] ?? 0); ?>">$0</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Inventario activo (a costo)</div>
            <div class="kpi-value" data-kpi="<?php echo (float)($inventario['valor_costo'] ?? 0); ?>">$0</div>
            <div style="font-size:.78rem;opacity:.82;margin-top:6px;"><?php echo (int)($inventario['productos_activos'] ?? 0); ?> productos · Potencial de venta: $<?php echo number_format((float)($inventario['valor_venta'] ?? 0), 2); ?></div>
        </div>
    </div>

    <div class="charts-grid">
        <div class="chart-card">
            <h3 class="chart-title">Tendencia 14 días (Ventas vs Contado)</h3>
            <canvas id="chartTrend" height="120"></canvas>
        </div>
        <div class="chart-card">
            <h3 class="chart-title">Mix de Cobro 14 días</h3>
            <canvas id="chartMetodos" height="120"></canvas>
        </div>
    </div>

    <?php if (!empty($terminalesResumen)): ?>
        <div class="card" style="margin:0 0 20px;">
            <h3 style="margin-top:0; color:#1e3a8a;">Cobros por terminal (últimos 30 días)</h3>
            <div class="stats-grid">
                <?php foreach ($terminalesResumen as $terminal): ?>
                    <div class="kpi-card">
                        <div class="kpi-label"><?php echo htmlspecialchars((string) $terminal['terminal']); ?></div>
                        <div class="kpi-value">$<?php echo number_format((float) $terminal['cobrado'], 2); ?></div>
                        <div style="font-size:.8rem;color:#64748b;margin-top:6px;"><?php echo (int) $terminal['operaciones']; ?> operación(es) · Comisión cliente: $<?php echo number_format((float) $terminal['recargo'], 2); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card" style="margin: 0 0 20px;">
        <h3 style="margin-top: 0; color: #1e3a8a;">Estadísticas mensuales</h3>
        <div class="stats-grid">
            <div class="kpi-card">
                <div class="kpi-label">Ventas acumuladas</div>
                <div class="kpi-value">$<?php echo number_format((float)($mensual['ventas_acumuladas'] ?? 0), 2); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Ticket promedio</div>
                <div class="kpi-value">$<?php echo number_format((float)($mensual['ticket_promedio'] ?? 0), 2); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Cortes del mes</div>
                <div class="kpi-value"><?php echo (int)($mensual['total_cortes'] ?? 0); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Cortes con faltante</div>
                <div class="kpi-value"><?php echo (int)($mensual['cortes_con_faltante'] ?? 0); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Cobrado en terminal</div>
                <div class="kpi-value">$<?php echo number_format((float)($metodosMes['cobrado_terminal'] ?? 0), 2); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Comisión pagada por clientes</div>
                <div class="kpi-value">$<?php echo number_format((float)($metodosMes['recargo_cliente_tarjeta'] ?? 0), 2); ?></div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-card">
                <h3 class="chart-title">Ventas diarias del mes</h3>
                <?php if (!empty($ventasMesTotales)): ?>
                    <?php $maxVentasMes = max($ventasMesTotales); ?>
                    <div style="display:grid; gap:10px;">
                        <?php foreach ($ventasMesLabels as $idx => $dia): ?>
                            <?php $valor = (float)$ventasMesTotales[$idx]; ?>
                            <div>
                                <div style="display:flex;justify-content:space-between;font-size:.82rem;color:#475569;margin-bottom:4px;"><span>Día <?php echo $dia; ?></span><strong>$<?php echo number_format($valor, 2); ?></strong></div>
                                <div style="height:12px;background:#e2e8f0;border-radius:999px;overflow:hidden;"><div style="height:100%;width:<?php echo $maxVentasMes > 0 ? max(8, ($valor / $maxVentasMes) * 100) : 0; ?>%;background:linear-gradient(90deg,#2563eb,#1d4ed8);border-radius:999px;"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:#64748b;">Aún no hay ventas registradas este mes.</p>
                <?php endif; ?>
            </div>

            <div class="chart-card">
                <h3 class="chart-title">Top categorías del mes</h3>
                <?php if (!empty($topCategoriasTotales)): ?>
                    <?php $maxCat = max($topCategoriasTotales); ?>
                    <div style="display:grid; gap:10px;">
                        <?php foreach ($topCategoriasLabels as $idx => $cat): ?>
                            <?php $valor = (int)$topCategoriasTotales[$idx]; ?>
                            <div>
                                <div style="display:flex;justify-content:space-between;font-size:.82rem;color:#475569;margin-bottom:4px;"><span><?php echo htmlspecialchars($cat); ?></span><strong><?php echo $valor; ?></strong></div>
                                <div style="height:12px;background:#e2e8f0;border-radius:999px;overflow:hidden;"><div style="height:100%;width:<?php echo $maxCat > 0 ? max(8, ($valor / $maxCat) * 100) : 0; ?>%;background:linear-gradient(90deg,#10b981,#059669);border-radius:999px;"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:#64748b;">Aún no hay suficientes datos para categorías este mes.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="corte-bars">
            <div class="corte-bar">
                <div class="corte-bar-head"><span>Efectivo del mes</span><strong>$<?php echo number_format((float)($metodosMes['efectivo'] ?? 0), 2); ?></strong></div>
                <div class="corte-bar-track"><div class="corte-bar-fill fill-efectivo" style="width: <?php echo max(5, min(100, ((float)($metodosMes['efectivo'] ?? 0) / max((float)($mensual['ventas_acumuladas'] ?? 1), 1)) * 100)); ?>%;"></div></div>
            </div>
            <div class="corte-bar">
                <div class="corte-bar-head"><span>Tarjeta del mes</span><strong>$<?php echo number_format((float)($metodosMes['tarjeta'] ?? 0), 2); ?></strong></div>
                <div class="corte-bar-track"><div class="corte-bar-fill fill-tarjeta" style="width: <?php echo max(5, min(100, ((float)($metodosMes['tarjeta'] ?? 0) / max((float)($mensual['ventas_acumuladas'] ?? 1), 1)) * 100)); ?>%;"></div></div>
            </div>
            <div class="corte-bar">
                <div class="corte-bar-head"><span>Transferencia del mes</span><strong>$<?php echo number_format((float)($metodosMes['transferencia'] ?? 0), 2); ?></strong></div>
                <div class="corte-bar-track"><div class="corte-bar-fill fill-transferencia" style="width: <?php echo max(5, min(100, ((float)($metodosMes['transferencia'] ?? 0) / max((float)($mensual['ventas_acumuladas'] ?? 1), 1)) * 100)); ?>%;"></div></div>
            </div>
        </div>
    </div>

    <h3 style="margin: 4px 0 0; color: #1e3a8a;">Cortes anteriores</h3>
    <input type="text" id="buscador" placeholder="🔍 Buscar por cajero o nota..."
           style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; margin: 15px 0; box-sizing: border-box;">

    <div class="tabla-responsiva">
        <table>
            <thead>
                <tr>
                    <th>Fecha/Hora</th>
                    <th>Cajero</th>
                    <th>Inicial</th>
                    <th>Ventas</th>
                    <th>Desglose</th>
                    <th>Gastos</th>
                    <th>Inyección</th>
                    <th>Real</th>
                    <th>Diferencia</th>
                    <th>Notas</th>
                </tr>
            </thead>
            <tbody id="tabla-cuerpos">
                <?php if ($resultado instanceof mysqli_result && mysqli_num_rows($resultado) > 0): ?>
                <?php while ($c = mysqli_fetch_assoc($resultado)):
                    $clase_dif = (float) $c['diferencia'] >= 0 ? 'diferencia-positiva' : 'diferencia-negativa';
                    $fechaOperativa = nuviFechaOperativaCorte(
                        (string) $c['fecha_cierre'],
                        $c['fecha_corte'] ?? null
                    );
                    $metodos = $metodosPorFecha[$fechaOperativa] ?? [
                        'efectivo' => 0,
                        'tarjeta' => 0,
                        'cobrado_terminal' => 0,
                        'recargo_cliente_tarjeta' => 0,
                        'transferencia' => 0,
                        'fiado' => 0,
                        'otro' => 0,
                    ];
                ?>
                <tr data-id="<?php echo $c['id']; ?>">
                    <td>
                        <a href="corte_caja.php?editar_id=<?php echo $c['id']; ?>" class="btn-edit">✏️ Editar</a>
                        <span class="badge"><?php echo date('d/m/y H:i', strtotime($c['fecha_cierre'])); ?></span>
                    </td>
                    <td><?php echo htmlspecialchars((string) $c['cajero']); ?></td>
                    <td>$<?php echo number_format($c['monto_inicial'], 2); ?></td>
                    <td>$<?php echo number_format($c['ventas_totales'], 2); ?></td>
                    <td class="metodos-resumen">
                        <strong>Efe:</strong> $<?php echo number_format($metodos['efectivo'] ?? 0, 2); ?><br>
                        <strong>Tar:</strong> $<?php echo number_format($metodos['tarjeta'] ?? 0, 2); ?><br>
                        <strong>Terminal:</strong> $<?php echo number_format($metodos['cobrado_terminal'] ?? 0, 2); ?><br>
                        <strong>Com. cliente:</strong> $<?php echo number_format($metodos['recargo_cliente_tarjeta'] ?? 0, 2); ?><br>
                        <strong>Trans:</strong> $<?php echo number_format($metodos['transferencia'] ?? 0, 2); ?><br>
                        <strong>Fiado:</strong> $<?php echo number_format($metodos['fiado'] ?? 0, 2); ?><br>
                        <strong>Otro:</strong> $<?php echo number_format($metodos['otro'] ?? 0, 2); ?>
                    </td>
                    <td class="editable" contenteditable="true" onblur="guardarCambioCorte(this, 'gastos_dia')"><?php echo $c['gastos_dia']; ?></td>
                    <td class="editable" contenteditable="true" onblur="guardarCambioCorte(this, 'dinero_personal_inyeccion')"><?php echo $c['dinero_personal_inyeccion']; ?></td>
                    <td><strong>$<?php echo number_format($c['monto_real_contado'], 2); ?></strong></td>
                    <td class="<?php echo $clase_dif; ?>">$<?php echo number_format($c['diferencia'], 2); ?></td>
                    <td class="editable" contenteditable="true" onblur="guardarCambioCorte(this, 'observaciones')"><?php echo htmlspecialchars((string) $c['observaciones']); ?></td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="10" style="text-align:center; color:#64748b; padding:22px;">No hay cortes anteriores por mostrar.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
const trendLabels = <?php echo json_encode($trendLabels, JSON_UNESCAPED_UNICODE); ?>;
const trendVentas = <?php echo json_encode($trendVentas); ?>;
const trendContado = <?php echo json_encode($trendContado); ?>;
const trendDiferencia = <?php echo json_encode($trendDif); ?>;

const metodosLabels = ['Efectivo', 'Tarjeta', 'Transferencia', 'Fiado', 'Otro'];
const metodosValues = [
    <?php echo (float)($metodosStats['efectivo'] ?? 0); ?>,
    <?php echo (float)($metodosStats['tarjeta'] ?? 0); ?>,
    <?php echo (float)($metodosStats['transferencia'] ?? 0); ?>,
    <?php echo (float)($metodosStats['fiado'] ?? 0); ?>,
    <?php echo (float)($metodosStats['otro'] ?? 0); ?>
];

function formatMoney(v) {
    return '$' + Number(v || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function animateKPIs() {
    document.querySelectorAll('[data-kpi]').forEach(el => {
        const target = Number(el.getAttribute('data-kpi') || 0);
        const isMoney = String(el.textContent || '').includes('$') || Math.abs(target) > 999;
        const duration = 750;
        const start = performance.now();

        function frame(now) {
            const progress = Math.min((now - start) / duration, 1);
            const value = target * progress;
            el.textContent = isMoney ? formatMoney(value) : Math.round(value).toLocaleString('es-MX');
            if (progress < 1) requestAnimationFrame(frame);
        }

        requestAnimationFrame(frame);
    });
}

function renderCharts() {
    // El historial sigue funcionando en red local aunque el CDN no esté disponible.
    if (typeof Chart !== 'function') {
        document.querySelectorAll('.chart-card canvas').forEach(canvas => {
            canvas.replaceWith(Object.assign(document.createElement('p'), {
                textContent: 'Gráfica no disponible sin conexión a internet; las estadísticas siguen visibles arriba.',
                style: 'color:#64748b; margin:12px 0;'
            }));
        });
        return;
    }
    const temaOscuro = document.documentElement.classList.contains('nuvi-tema-oscuro');
    Chart.defaults.color = temaOscuro ? '#cbd5e1' : '#475569';
    Chart.defaults.borderColor = temaOscuro ? '#334155' : '#e2e8f0';
    const trendCtx = document.getElementById('chartTrend');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: 'Ventas',
                        data: trendVentas,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.12)',
                        fill: true,
                        tension: 0.35
                    },
                    {
                        label: 'Contado',
                        data: trendContado,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.10)',
                        fill: true,
                        tension: 0.35
                    },
                    {
                        label: 'Diferencia',
                        data: trendDiferencia,
                        borderColor: '#f59e0b',
                        borderDash: [6, 4],
                        fill: false,
                        tension: 0.25
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y)}`
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: (v) => '$' + Number(v).toLocaleString('es-MX')
                        }
                    }
                }
            }
        });
    }

    const metodosCtx = document.getElementById('chartMetodos');
    if (metodosCtx) {
        new Chart(metodosCtx, {
            type: 'doughnut',
            data: {
                labels: metodosLabels,
                datasets: [{
                    data: metodosValues,
                    backgroundColor: ['#10b981', '#2563eb', '#7c3aed', '#f59e0b', '#64748b'],
                    borderWidth: 1,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.label}: ${formatMoney(ctx.parsed)}`
                        }
                    }
                }
            }
        });
    }
}

function guardarCambioCorte(elemento, columna) {
    let idCorte = elemento.parentElement.getAttribute('data-id');
    let nuevoValor = elemento.innerText.trim();
    let datos = new FormData();
    datos.append('id', idCorte);
    datos.append('columna', columna);
    datos.append('valor', nuevoValor);

    fetch('actualizar_corte.php', { method: 'POST', body: datos })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            elemento.style.backgroundColor = "#dcfce7";
            setTimeout(() => {
                elemento.style.backgroundColor = "";
                if (columna !== 'observaciones') location.reload();
            }, 800);
        } else {
            alert(res.message || 'No se pudo guardar el corte.');
        }
    }).catch(() => alert('No se pudo guardar el corte.'));
}

document.getElementById('buscador').addEventListener('keyup', function() {
    let filtro = this.value.toLowerCase();
    let filas = document.querySelectorAll('#tabla-cuerpos tr');
    filas.forEach(f => f.style.display = f.innerText.toLowerCase().includes(filtro) ? '' : 'none');
});

function cargarCharts() {
    if (typeof Chart === 'function') return Promise.resolve();
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const timeout = setTimeout(() => {
            script.remove();
            reject(new Error('Tiempo agotado'));
        }, 5000);
        script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
        script.async = true;
        script.onload = () => { clearTimeout(timeout); resolve(); };
        script.onerror = () => { clearTimeout(timeout); reject(new Error('No disponible')); };
        document.head.appendChild(script);
    });
}

animateKPIs();
cargarCharts().catch(() => null).finally(renderCharts);
</script>
</body>
</html>
