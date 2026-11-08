<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('app_header.php');

$desde = date('Y-m-01');
$hasta = date('Y-m-d');
$error = trim((string) ($_GET['error'] ?? ''));
$mensajes = [
    'fecha_invalida' => 'Revisa el rango de fechas seleccionado.',
    'periodo_demasiado_largo' => 'Para mantener el sistema ágil, exporta periodos de hasta dos años por archivo.',
    'tipo_invalido' => 'El reporte solicitado no existe.',
    'estructura_pendiente' => 'Ese reporte requiere preparar primero la estructura de abastecimiento.',
    'estructura_emergencia' => 'Ese reporte requiere preparar primero la sincronización de emergencia.',
    'error_reporte' => 'No se pudo generar el reporte. Inténtalo de nuevo o avisa a administración.',
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reportes exportables · Tienda Nuvi</title>
    <style>
        * { box-sizing:border-box; font-family:'Segoe UI',system-ui,sans-serif; }
        body { margin:0; padding:12px; background:#f4f6f8; color:#1e293b; }
        .contenedor { max-width:1050px; margin:auto; padding:28px; background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 10px 25px rgba(15,23,42,.06); }
        h1 { margin:0 0 7px; font-size:clamp(1.45rem,4vw,2rem); } .subtitulo { margin:0 0 24px; color:#64748b; line-height:1.55; }
        .aviso { margin:0 0 18px; padding:12px 14px; border:1px solid #fecaca; border-radius:10px; background:#fef2f2; color:#991b1b; font-weight:700; }
        .filtros { display:flex; flex-wrap:wrap; gap:14px; align-items:end; margin:0 0 22px; padding:16px; border:1px solid #dbeafe; border-radius:12px; background:#eff6ff; }
        .campo { display:grid; gap:6px; min-width:185px; } label { font-size:.9rem; color:#334155; font-weight:800; } input { width:100%; padding:10px; border:1px solid #94a3b8; border-radius:8px; background:#fff; color:#1e293b; font:inherit; }
        .ayuda { flex:1 1 240px; margin:0; color:#475569; font-size:.86rem; line-height:1.4; }
        .reportes { display:grid; grid-template-columns:repeat(auto-fit,minmax(235px,1fr)); gap:14px; }
        .reporte { position:relative; display:flex; flex-direction:column; gap:8px; min-height:178px; padding:18px; border:2px solid #e2e8f0; border-radius:13px; background:#f8fafc; cursor:pointer; transition:border-color .15s ease, transform .15s ease, box-shadow .15s ease; }
        .reporte:hover { transform:translateY(-2px); border-color:#93c5fd; box-shadow:0 10px 18px -15px rgba(30,64,175,.7); }
        .reporte:has(input:checked) { border-color:#2563eb; background:#eff6ff; }
        .reporte input { position:absolute; top:15px; right:15px; width:18px; height:18px; accent-color:#2563eb; }
        .reporte h2 { margin:0; padding-right:28px; font-size:1.07rem; } .reporte p { margin:0; color:#64748b; font-size:.9rem; line-height:1.45; }
        .reporte small { margin-top:auto; color:#0f766e; font-weight:800; }
        .acciones { display:flex; gap:10px; flex-wrap:wrap; margin-top:24px; } button, .volver { min-height:44px; padding:10px 16px; border:1px solid transparent; border-radius:9px; font:inherit; font-weight:800; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
        button { background:#2563eb; color:#fff; } button:hover { background:#1d4ed8; } .volver { color:#334155; background:#f8fafc; border-color:#cbd5e1; }
        .nota { margin:20px 0 0; padding:12px 14px; border-radius:10px; background:#fffbeb; border:1px solid #fde68a; color:#854d0e; font-size:.9rem; line-height:1.45; }
        html.nuvi-tema-oscuro .contenedor { background:#172033 !important; border-color:#334155 !important; }
        html.nuvi-tema-oscuro .subtitulo, html.nuvi-tema-oscuro .ayuda, html.nuvi-tema-oscuro .reporte p { color:#cbd5e1 !important; }
        html.nuvi-tema-oscuro .filtros, html.nuvi-tema-oscuro .reporte:has(input:checked) { background:#1e3a5f !important; border-color:#3b82f6 !important; }
        html.nuvi-tema-oscuro .reporte { background:#1e293b !important; border-color:#475569 !important; }
        html.nuvi-tema-oscuro .reporte:has(input:checked) { border-color:#60a5fa !important; }
        html.nuvi-tema-oscuro .volver { background:#1e293b !important; color:#e2e8f0 !important; border-color:#475569 !important; }
        html.nuvi-tema-oscuro .nota { background:#3b2b12 !important; border-color:#b7791f !important; color:#fde68a !important; }
        html.nuvi-tema-oscuro .aviso { background:#451a1a !important; border-color:#b91c1c !important; color:#fecaca !important; }
        @media (max-width:600px) { body { padding:8px; } .contenedor { padding:20px 15px; } .filtros { align-items:stretch; } .campo { width:100%; } .acciones>* { width:100%; } }
    </style>
    <?php renderAppHeaderStyles(); ?>
</head>
<body>
    <?php renderAppHeader('historial_cortes', true); ?>
    <main class="contenedor">
        <h1>📤 Reportes exportables</h1>
        <p class="subtitulo">Descarga información para administración o contador. Los archivos CSV se abren directamente con Excel, LibreOffice o Google Sheets.</p>
        <?php if (isset($mensajes[$error])): ?><div class="aviso" role="alert"><?php echo htmlspecialchars($mensajes[$error], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <form method="get" action="exportar_reporte.php">
            <section class="filtros" aria-label="Rango de fechas">
                <div class="campo"><label for="desde">Desde</label><input id="desde" type="date" name="desde" value="<?php echo htmlspecialchars($desde, ENT_QUOTES, 'UTF-8'); ?>" required></div>
                <div class="campo"><label for="hasta">Hasta</label><input id="hasta" type="date" name="hasta" value="<?php echo htmlspecialchars($hasta, ENT_QUOTES, 'UTF-8'); ?>" required></div>
                <p class="ayuda">El rango aplica a ventas, cortes y abastecimiento. Inventario y libreta muestran una fotografía actual.</p>
            </section>
            <section class="reportes" aria-label="Tipo de reporte">
                <label class="reporte"><input type="radio" name="tipo" value="ventas" checked><h2>🛒 Ventas detalladas</h2><p>Folio, fecha, cajero, pago recibido, cambio, pagos mixtos, terminal y nota.</p><small>Aplica rango de fechas</small></label>
                <label class="reporte"><input type="radio" name="tipo" value="resumen_ventas"><h2>📈 Resumen diario</h2><p>Ventas por día, efectivo, tarjeta, transferencias, fiado, recargos y artículos vendidos.</p><small>Aplica rango de fechas</small></label>
                <label class="reporte"><input type="radio" name="tipo" value="emergencia"><h2>🕒 Ventas de emergencia recibidas</h2><p>Compara la hora de recepción con la hora UTC registrada por la tablet, sin modificar cortes.</p><small>El rango selecciona por fecha de recepción</small></label>
                <label class="reporte"><input type="radio" name="tipo" value="cortes"><h2>📊 Cortes de caja</h2><p>Fondos, ventas, gastos, inyecciones, contado, diferencia y observaciones.</p><small>Aplica rango de fechas</small></label>
                <label class="reporte"><input type="radio" name="tipo" value="inventario"><h2>📦 Inventario actual</h2><p>Código, producto, marca/proveedor, unidad, existencias, costos y precios vigentes.</p><small>Fotografía actual</small></label>
                <label class="reporte"><input type="radio" name="tipo" value="libreta"><h2>📒 Libreta digital</h2><p>Clientes, contacto, límite de crédito, saldo actual y estado de su cuenta.</p><small>Fotografía actual</small></label>
                <label class="reporte"><input type="radio" name="tipo" value="abastecimiento"><h2>📥 Compras y mermas</h2><p>Entradas de mercancía y mermas por producto, costo, proveedor, folio y motivo.</p><small>Aplica rango de fechas</small></label>
            </section>
            <div class="acciones"><button type="submit">⬇️ Descargar CSV para Excel</button><a class="volver" href="consultar_cortes.php">← Volver al historial</a></div>
        </form>
        <p class="nota"><strong>Privacidad:</strong> estos archivos contienen datos operativos de la tienda. Guárdalos sólo en equipos autorizados y no los compartas por canales públicos.</p>
        <p class="nota"><strong>Revisión de emergencia:</strong> el rango busca la fecha en que el servidor recibió cada venta, no la fecha en que se cobró. La hora de la tablet está en UTC y puede ser incorrecta si su reloj no estaba bien ajustado. Comprueba el corte y la diferencia antes de cualquier corrección; el reporte no modifica cortes ni mueve ventas entre días. Las ventas todavía pendientes solo están en la tablet.</p>
    </main>
</body>
</html>
