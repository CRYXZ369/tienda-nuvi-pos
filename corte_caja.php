<?php
include("auth.php");
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
    // Una venta mixta entra en el corte por cada parte real del cobro, no como
    // el total duplicado en efectivo y tarjeta. Las ventas antiguas, que aún
    // no tienen renglones en pagos_venta, conservan el cálculo anterior.
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

$nombre_user = $_SESSION['nombre'];
$ventas_efectivo = 0;
$ventas_tarjeta = 0;
$ventas_transferencia = 0;
$ventas_fiado = 0;
$ventas_otro = 0;
$ventas_generales = 0;

function nuviFechaCorteValida($fecha): ?string
{
    $texto = trim((string) $fecha);
    if ($texto === '') {
        return null;
    }

    $fechaObj = DateTime::createFromFormat('!Y-m-d', $texto);
    $errores = DateTime::getLastErrors();
    if (!$fechaObj || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0)) || $fechaObj->format('Y-m-d') !== $texto) {
        return null;
    }
    return $fechaObj > new DateTime('today') ? null : $texto;
}

function nuviFechaOperativaActual(): string
{
    $fecha = new DateTime();
    if ((int) $fecha->format('H') < 6) {
        $fecha->modify('-1 day');
    }
    return $fecha->format('Y-m-d');
}

function nuviFechaOperativaDesdeCierre($fechaCierre): string
{
    $fecha = new DateTime((string) $fechaCierre);
    if ((int) $fecha->format('H') < 6) {
        $fecha->modify('-1 day');
    }
    return $fecha->format('Y-m-d');
}

// ============================================
// LÓGICA PARA DETECTAR SI ES EDICIÓN O NUEVO
// ============================================
$id_editar = isset($_GET['editar_id']) ? intval($_GET['editar_id']) : null;
$es_edicion = ($id_editar !== null);
$fechaSolicitada = nuviFechaCorteValida($_GET['fecha_corte'] ?? '');

if ($es_edicion) {
    // Si editamos, traemos los datos de la base de datos
    $sql_edit = "SELECT * FROM cortes_caja WHERE id = $id_editar";
    $res_edit = mysqli_query($conexion, $sql_edit);
    $c = mysqli_fetch_assoc($res_edit);

    $fondo_sugerido = $c['monto_inicial'];
    $total_sistema = $c['ventas_totales'];
    $gastos_valor = $c['gastos_dia'];
    $inyeccion_valor = $c['dinero_personal_inyeccion'];
    $real_valor = $c['monto_real_contado'];
    $notas_valor = $c['observaciones'];
    $fechaOperativa = nuviFechaCorteValida($c['fecha_corte'] ?? '')
        ?? nuviFechaOperativaDesdeCierre($c['fecha_cierre']);
    $fechaOperativaDisplay = date_create_from_format('Y-m-d', $fechaOperativa)->format('d/m/Y');
} else {
    // De madrugada, el valor predeterminado es el día que acaba de cerrar.
    // También se puede elegir otro día desde la pantalla, sin editar código.
    $fechaOperativa = $fechaSolicitada ?? nuviFechaOperativaActual();
    $fechaOperativaDisplay = date_create_from_format('Y-m-d', $fechaOperativa)->format('d/m/Y');

    // Fondo sugerido (último cierre real)
    $sql_ultimo = "SELECT monto_real_contado FROM cortes_caja ORDER BY id DESC LIMIT 1";
    $fondo_sugerido = mysqli_fetch_assoc(mysqli_query($conexion, $sql_ultimo))['monto_real_contado'] ?? 0;

    // Valores vacíos para nuevo registro
    $gastos_valor = $inyeccion_valor = $real_valor = $notas_valor = "";
}

$fechaBase = DateTime::createFromFormat('Y-m-d H:i:s', $fechaOperativa . ' 12:00:00');
if (!$fechaBase) {
    $fechaBase = new DateTime();
}

$sql_ventas = "SELECT
                COALESCE(SUM(total), 0) AS total_general,
                $exprEfectivo AS total_efectivo,
                $exprTarjeta AS total_tarjeta,
                $exprCobradoTerminal AS total_cobrado_terminal,
                $exprRecargoClienteTarjeta AS total_recargo_cliente_tarjeta,
                $exprTransferencia AS total_transferencia,
                $exprFiado AS total_fiado,
                $exprOtro AS total_otro
            FROM ventas
            WHERE (DATE(fecha_venta) = '$fechaOperativa' AND HOUR(fecha_venta) >= 6)
               OR (DATE(fecha_venta) = DATE_ADD('$fechaOperativa', INTERVAL 1 DAY) AND HOUR(fecha_venta) < 6)";
$res_ventas = mysqli_query($conexion, $sql_ventas);
$ventas_metodo = $res_ventas ? mysqli_fetch_assoc($res_ventas) : [
    'total_general' => 0,
    'total_efectivo' => 0,
    'total_tarjeta' => 0,
    'total_cobrado_terminal' => 0,
    'total_recargo_cliente_tarjeta' => 0,
    'total_transferencia' => 0,
    'total_fiado' => 0,
    'total_otro' => 0,
];

$ventas_generales = (float)($ventas_metodo['total_general'] ?? 0);
$ventas_efectivo = (float)($ventas_metodo['total_efectivo'] ?? 0);
$ventas_tarjeta = (float)($ventas_metodo['total_tarjeta'] ?? 0);
$ventas_cobradas_terminal = (float)($ventas_metodo['total_cobrado_terminal'] ?? 0);
$recargos_clientes_tarjeta = (float)($ventas_metodo['total_recargo_cliente_tarjeta'] ?? 0);
$ventas_transferencia = (float)($ventas_metodo['total_transferencia'] ?? 0);
$ventas_fiado = (float)($ventas_metodo['total_fiado'] ?? 0);
$ventas_otro = (float)($ventas_metodo['total_otro'] ?? 0);

// Patrimonio de mercancía disponible: se muestra separado de las ventas del
// día para que sirva como referencia al valuar la tienda, sin afectar el corte.
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

if (!$es_edicion) {
    $total_sistema = $ventas_efectivo;
}

$mesActual = $fechaBase->format('Y-m-01');
$siguienteMes = (clone $fechaBase)->modify('first day of next month')->format('Y-m-01');
$mensualSql = "SELECT
    COUNT(*) AS total_ventas,
    COALESCE(SUM(total), 0) AS total_ventas_mes,
    COALESCE(AVG(total), 0) AS ticket_promedio,
    $exprEfectivo AS efectivo,
    $exprTarjeta AS tarjeta,
    $exprTransferencia AS transferencia,
    $exprFiado AS fiado,
    $exprOtro AS otro
    FROM ventas
    WHERE fecha_venta >= '$mesActual' AND fecha_venta < '$siguienteMes'";
$mensualRes = mysqli_query($conexion, $mensualSql);
$mensual = $mensualRes ? mysqli_fetch_assoc($mensualRes) : [
    'total_ventas' => 0,
    'total_ventas_mes' => 0,
    'ticket_promedio' => 0,
    'efectivo' => 0,
    'tarjeta' => 0,
    'transferencia' => 0,
    'fiado' => 0,
    'otro' => 0
];

$ventasDiariasSql = "SELECT DATE(fecha_venta) AS fecha, COALESCE(SUM(total),0) AS total
    FROM ventas
    WHERE fecha_venta >= '$mesActual' AND fecha_venta < '$siguienteMes'
    GROUP BY DATE(fecha_venta)
    ORDER BY DATE(fecha_venta) ASC";
$ventasDiariasRes = mysqli_query($conexion, $ventasDiariasSql);
$ventasDias = [];
$ventasDiasTotales = [];
if ($ventasDiariasRes) {
    while ($fila = mysqli_fetch_assoc($ventasDiariasRes)) {
        $ventasDias[] = date('d', strtotime($fila['fecha']));
        $ventasDiasTotales[] = (float)$fila['total'];
    }
}

$categoriasSql = "SELECT COALESCE(p.categoria, 'Sin categoría') AS categoria, COUNT(*) AS total
    FROM ventas v
    JOIN detalle_ventas dv ON dv.id_venta = v.id_venta
    JOIN productos p ON p.id = dv.id_producto
    WHERE v.fecha_venta >= '$mesActual' AND v.fecha_venta < '$siguienteMes'
    GROUP BY COALESCE(p.categoria, 'Sin categoría')
    ORDER BY total DESC
    LIMIT 6";
$categoriasRes = mysqli_query($conexion, $categoriasSql);
$categoriasLabels = [];
$categoriasTotales = [];
if ($categoriasRes) {
    while ($fila = mysqli_fetch_assoc($categoriasRes)) {
        $categoriasLabels[] = $fila['categoria'];
        $categoriasTotales[] = (int)$fila['total'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title><?php echo $es_edicion ? "Editar Corte" : "Corte de Caja"; ?> | Nuvi</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f4f6f8;
            padding: 14px;
            margin: 0;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .grid-corte {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        input,
        textarea {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .btn-finalizar {
            background: <?php echo $es_edicion ? "#3b82f6" : "#10b981"; ?>;
            color: white;
            padding: 15px;
            border: none;
            width: 100%;
            cursor: pointer;
            font-weight: bold;
            border-radius: 8px;
            font-size: 1.1em;
        }

        .btn-finalizar:hover {
            opacity: 0.9;
        }

        .alerta-edicion {
            background: #e0f2fe;
            border-left: 5px solid #3b82f6;
            color: #0369a1;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alerta-error {
            background: #fef2f2;
            border-left: 5px solid #dc2626;
            color: #991b1b;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-weight: 700;
        }

        .ayuda-fecha-corte {
            display: block;
            margin: -4px 0 12px;
            color: #64748b;
            font-size: .82rem;
            line-height: 1.35;
        }

        .selector-dia-corte {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin: 0 0 18px;
            padding: 14px 16px;
            border: 1px solid #93c5fd;
            border-radius: 12px;
            background: #eff6ff;
            color: #1e3a8a;
        }
        .selector-dia-corte label { font-weight: 800; }
        .selector-dia-corte input { width: auto; min-width: 165px; margin: 0; font-weight: 700; }
        .selector-dia-corte small { color: #475569; }
        html.nuvi-tema-oscuro .selector-dia-corte { background: #172554 !important; border-color: #3b82f6 !important; color: #bfdbfe !important; }
        html.nuvi-tema-oscuro .selector-dia-corte small { color: #cbd5e1 !important; }

        .resumen-metodos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .metodo-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px;
        }

        .metodo-card strong {
            display: block;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .metodo-card span {
            font-size: 1.15rem;
            font-weight: bold;
        }

        .inventario-card {
            background: linear-gradient(135deg, #14532d, #166534);
            color: #fff;
            border-radius: 12px;
            padding: 14px;
            margin-top: 14px;
            box-shadow: 0 10px 24px rgba(20, 83, 45, .18);
        }
        .inventario-card small { display:block; opacity:.82; margin-bottom:6px; }
        .inventario-card strong { font-size:1.3rem; }
        .inventario-card span { display:block; font-size:.82rem; margin-top:6px; opacity:.9; }

        .corte-bars {
            display: grid;
            gap: 10px;
            margin: 10px 0 22px;
        }

        .corte-bar {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 12px;
        }

        .corte-bar-head {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
            font-size: .88rem;
            color: #334155;
        }

        .corte-bar-track {
            width: 100%;
            height: 12px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
        }

        .corte-bar-fill {
            height: 100%;
            border-radius: 999px;
            animation: growBar .9s ease both;
        }

        .fill-efectivo { background: linear-gradient(90deg, #10b981, #059669); }
        .fill-tarjeta { background: linear-gradient(90deg, #2563eb, #1d4ed8); }
        .fill-transferencia { background: linear-gradient(90deg, #7c3aed, #6d28d9); }
        .fill-fiado { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .fill-otro { background: linear-gradient(90deg, #64748b, #475569); }

        @keyframes growBar {
            from { width: 0; }
        }
    </style>
    <?php renderAppHeaderStyles(); ?>
</head>

<body>
    <?php renderAppHeader('corte', true); ?>

    <div style="max-width: 900px; margin: auto;">
        <?php if (($_GET['estado'] ?? '') === 'error_guardar'): ?>
            <div class="alerta-error" role="alert">No se guardó el corte. Revisa los importes obligatorios y vuelve a intentarlo; dejar “Inyección de dinero” vacío ahora equivale a $0.</div>
        <?php endif; ?>
        <?php if ($es_edicion): ?>
            <div class="alerta-edicion">
                <strong>Modo Edición:</strong> Estás corrigiendo los datos del corte del día <?php echo $fechaOperativaDisplay; ?>.
            </div>
        <?php endif; ?>
        <h1 style="display: inline-block;">📊 <?php echo $es_edicion ? "Editar" : "Generar"; ?> Corte - <?php echo $fechaOperativaDisplay; ?></h1>

        <?php if (!$es_edicion): ?>
            <div class="selector-dia-corte">
                <label for="fecha_corte">📅 Día que se está cerrando:</label>
                <input id="fecha_corte" type="date" value="<?php echo htmlspecialchars($fechaOperativa); ?>" max="<?php echo date('Y-m-d'); ?>" onchange="window.location.href = 'corte_caja.php?fecha_corte=' + encodeURIComponent(this.value);">
                <small>Después de medianoche se propone el día anterior. Al cambiarlo se actualiza el resumen.</small>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin-top: 0; color: #1e3a8a;">Resumen por Método de Pago</h3>
            <div class="resumen-metodos">
                <div class="metodo-card"><strong>Total General</strong><span>$<?php echo number_format($ventas_generales, 2); ?></span></div>
                <div class="metodo-card"><strong>Efectivo</strong><span style="color:#059669;">$<?php echo number_format($ventas_efectivo, 2); ?></span></div>
                <div class="metodo-card"><strong>Tarjeta (productos)</strong><span style="color:#2563eb;">$<?php echo number_format($ventas_tarjeta, 2); ?></span><small style="display:block; margin-top:6px; color:#64748b;">Cobrado en terminal: $<?php echo number_format($ventas_cobradas_terminal, 2); ?><br>Comisión cubierta por clientes: $<?php echo number_format($recargos_clientes_tarjeta, 2); ?></small></div>
                <div class="metodo-card"><strong>Transferencia</strong><span style="color:#7c3aed;">$<?php echo number_format($ventas_transferencia, 2); ?></span></div>
                <div class="metodo-card"><strong>Fiado</strong><span style="color:#b45309;">$<?php echo number_format($ventas_fiado, 2); ?></span></div>
                <div class="metodo-card"><strong>Otro</strong><span>$<?php echo number_format($ventas_otro, 2); ?></span></div>
            </div>
            <div class="inventario-card">
                <small>Valor actual del inventario activo · <?php echo (int)($inventario['productos_activos'] ?? 0); ?> productos</small>
                <strong>A costo: $<?php echo number_format((float)($inventario['valor_costo'] ?? 0), 2); ?></strong>
                <span>Valor potencial de venta: $<?php echo number_format((float)($inventario['valor_venta'] ?? 0), 2); ?>. No forma parte del corte de hoy.</span>
            </div>
            <?php
                $maxMetodo = max($ventas_efectivo, $ventas_tarjeta, $ventas_transferencia, $ventas_fiado, $ventas_otro, 1);
            ?>
            <div class="corte-bars">
                <div class="corte-bar">
                    <div class="corte-bar-head"><span>Efectivo</span><strong>$<?php echo number_format($ventas_efectivo, 2); ?></strong></div>
                    <div class="corte-bar-track"><div class="corte-bar-fill fill-efectivo" style="width: <?php echo ($ventas_efectivo / $maxMetodo) * 100; ?>%;"></div></div>
                </div>
                <div class="corte-bar">
                    <div class="corte-bar-head"><span>Tarjeta</span><strong>$<?php echo number_format($ventas_tarjeta, 2); ?></strong></div>
                    <div class="corte-bar-track"><div class="corte-bar-fill fill-tarjeta" style="width: <?php echo ($ventas_tarjeta / $maxMetodo) * 100; ?>%;"></div></div>
                </div>
                <div class="corte-bar">
                    <div class="corte-bar-head"><span>Transferencia</span><strong>$<?php echo number_format($ventas_transferencia, 2); ?></strong></div>
                    <div class="corte-bar-track"><div class="corte-bar-fill fill-transferencia" style="width: <?php echo ($ventas_transferencia / $maxMetodo) * 100; ?>%;"></div></div>
                </div>
                <div class="corte-bar">
                    <div class="corte-bar-head"><span>Fiado</span><strong>$<?php echo number_format($ventas_fiado, 2); ?></strong></div>
                    <div class="corte-bar-track"><div class="corte-bar-fill fill-fiado" style="width: <?php echo ($ventas_fiado / $maxMetodo) * 100; ?>%;"></div></div>
                </div>
            </div>
            <p style="margin: 0; color: #475569;">Para el corte físico se usa el total en <strong>efectivo</strong>, porque tarjeta, transferencia y fiado no deben contarse como dinero en caja. El cobro en terminal incluye el recargo pagado por el cliente y no aumenta las ventas de productos.</p>
        </div>


        <form action="guardar_corte.php" method="POST">
            <?php if ($es_edicion): ?> <input type="hidden" name="id_corte" value="<?php echo $id_editar; ?>"> <?php endif; ?>
            <input type="hidden" name="fecha_corte" value="<?php echo htmlspecialchars($fechaOperativa); ?>">

            <div class="grid-corte">
                <div class="card">
                    <h3 style="color: #059669;">➕ Entradas (Dinero que entró)</h3>
                    <label>Dinero Inicial (Sugerido de ayer):</label>
                    <input type="number" name="monto_inicial" step="0.01" value="<?php echo $fondo_sugerido; ?>" required>

                    <label>Ventas en Efectivo del Sistema (Solo lectura):</label>
                    <input type="number" name="total_sistema" value="<?php echo $total_sistema; ?>" readonly style="background: #e9ecef; font-weight: bold; color: #1e40af;">

                    <label>Inyección de Dinero (Dinero puesto por la familia):</label>
                    <input type="number" name="dinero_personal" step="0.01" value="<?php echo $inyeccion_valor; ?>" placeholder="Ej: $500 para cambio">
                </div>

                <div class="card">
                    <h3 style="color: #dc2626;">➖ Salidas (Gastos)</h3>
                    <label>Gastos en Proveedores / Compras:</label>
                    <input type="number" name="gastos_total" step="0.01" value="<?php echo $gastos_valor; ?>" placeholder="Ej: $1200 en mercancía" required>

                    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;">

                    <h3 style="color: #2563eb;">💰 Dinero Real en Mano</h3>
                    <label><strong>Dinero físico total contado:</strong></label>
                    <input type="number" name="dinero_real" step="0.01" value="<?php echo $real_valor; ?>" required style="border: 2px solid #2563eb; font-size: 1.2em;">
                </div>
            </div>

            <div class="card">
                <label>Notas del día (Opcional):</label>
                <textarea name="notas" placeholder="Ej. Vino el del gas, se pagó a Bimbo..."><?php echo $notas_valor; ?></textarea>
                <button type="submit" class="btn-finalizar">
                    <?php echo $es_edicion ? "GUARDAR CAMBIOS" : "GENERAR CORTE Y GUARDAR"; ?>
                </button>
            </div>
        </form>

    </div>
</body>

</html>
