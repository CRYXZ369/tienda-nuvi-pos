<?php
require_once('auth.php');
tienePermiso(['admin']);
require_once('conexion.php');
require_once('app_header.php');
require_once('auditoria_utils.php');

function nuviEstadoRespaldo(): array
{
    $archivo = __DIR__ . '/logs/estado_respaldo.json';
    $base = [
        'estado' => 'sin_respaldo',
        'fecha' => null,
        'edad_horas' => null,
        'mensaje' => 'Aún no existe un respaldo automático verificado.',
    ];
    if (!is_file($archivo) || !is_readable($archivo)) {
        return $base;
    }

    $contenido = @file_get_contents($archivo);
    $datos = is_string($contenido) ? json_decode($contenido, true) : null;
    if (!is_array($datos) || empty($datos['estado']) || empty($datos['fecha'])) {
        return [
            'estado' => 'error',
            'fecha' => null,
            'edad_horas' => null,
            'mensaje' => 'El archivo de estado del respaldo no se puede leer.',
        ];
    }

    $marcaTiempo = strtotime((string) $datos['fecha']);
    if ($marcaTiempo === false) {
        return [
            'estado' => 'error',
            'fecha' => null,
            'edad_horas' => null,
            'mensaje' => 'La fecha del último estado de respaldo es inválida.',
        ];
    }

    $edadHoras = max(0, (int) floor((time() - $marcaTiempo) / 3600));
    $estado = (string) $datos['estado'];
    if ($estado === 'correcto' && $edadHoras > 36) {
        $estado = 'vencido';
    }

    return [
        'estado' => $estado,
        'fecha' => date('d/m/Y H:i', $marcaTiempo),
        'edad_horas' => $edadHoras,
        'mensaje' => mb_substr(trim((string) ($datos['mensaje'] ?? 'Sin detalle.')), 0, 300),
    ];
}

$estadoRespaldo = nuviEstadoRespaldo();
$disponible = isset($conexion) && $conexion instanceof mysqli && nuviAuditoriaDisponible($conexion);
$accionesPermitidas = [
    'venta_creada', 'venta_fiada_creada', 'venta_editada', 'venta_devuelta', 'ticket_impreso', 'ticket_copia_impresa',
    'producto_creado', 'producto_actualizado', 'producto_desactivado',
    'libreta_abono', 'libreta_ajustada', 'libreta_recargo_diario', 'libreta_recargo_semanal', 'libreta_recargo_corregido_semanal', 'libreta_interes_simple', 'cliente_creado', 'cliente_actualizado', 'cliente_archivado', 'cliente_desarchivado',
    'corte_creado', 'corte_actualizado', 'terminal_creada', 'terminal_actualizada',
    'terminal_estado_actualizado', 'usuario_creado', 'usuario_actualizado', 'usuario_desactivado',
    'reporte_exportado', 'error_navegador', 'comentario_error'
];
$entidadesPermitidas = ['venta', 'producto', 'cliente', 'corte', 'terminal', 'usuario', 'reporte', 'diagnostico'];
$accion = in_array($_GET['accion'] ?? '', $accionesPermitidas, true) ? $_GET['accion'] : '';
$entidad = in_array($_GET['entidad'] ?? '', $entidadesPermitidas, true) ? $_GET['entidad'] : '';
$registros = [];

if ($disponible) {
    $condiciones = [];
    if ($accion !== '') {
        $condiciones[] = "b.accion = '" . mysqli_real_escape_string($conexion, $accion) . "'";
    }
    if ($entidad !== '') {
        $condiciones[] = "b.entidad = '" . mysqli_real_escape_string($conexion, $entidad) . "'";
    }
    $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
    $sql = "SELECT b.*, COALESCE(u.nombre, 'Usuario no disponible') AS usuario_nombre
            FROM bitacora_auditoria b
            LEFT JOIN usuarios u ON u.id = b.id_usuario
            $where
            ORDER BY b.id DESC
            LIMIT 250";
    $resultado = mysqli_query($conexion, $sql);
    if ($resultado) {
        while ($fila = mysqli_fetch_assoc($resultado)) {
            $registros[] = $fila;
        }
    }
}

function nuviBitacoraDetalles(string $json): string
{
    $datos = json_decode($json, true);
    if (!is_array($datos) || !$datos) {
        return '';
    }
    return json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bitácora operativa | Tienda Nuvi</title>
    <?php renderAppHeaderStyles(); ?>
    <style>
        body { margin:0; padding:16px; background:#f4f6f8; color:#172033; font-family:Segoe UI,system-ui,sans-serif; }
        main { max-width:1280px; margin:0 auto; }
        .panel { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:22px; box-shadow:0 10px 24px rgba(15,23,42,.06); margin-bottom:18px; }
        h1 { margin:0 0 7px; font-size:1.55rem; } .intro,.muted { color:#64748b; line-height:1.45; } .intro { margin:0; }
        .notice { padding:14px; border-radius:10px; line-height:1.45; background:#fffbeb; border:1px solid #fcd34d; color:#92400e; }
        .filters { display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-top:18px; }
        .field { display:grid; gap:5px; min-width:190px; } label { font-size:.82rem; font-weight:700; color:#475569; }
        select,button { padding:10px 12px; border-radius:8px; border:1px solid #cbd5e1; font:inherit; }
        button { background:#2563eb; color:#fff; border-color:#2563eb; font-weight:700; cursor:pointer; }
        .btn-secondary { background:#f8fafc; color:#334155; border-color:#cbd5e1; text-decoration:none; display:inline-flex; align-items:center; }
        .table-wrap { overflow:auto; } table { width:100%; border-collapse:collapse; min-width:900px; }
        th,td { padding:12px 10px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; } th { color:#64748b; font-size:.78rem; text-transform:uppercase; }
        .tag { display:inline-block; padding:4px 8px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:.76rem; font-weight:700; white-space:nowrap; }
        .meta { color:#64748b; font-size:.82rem; margin-top:3px; } summary { cursor:pointer; color:#2563eb; font-weight:700; } pre { max-width:360px; max-height:180px; overflow:auto; margin:8px 0 0; padding:9px; border-radius:8px; background:#f8fafc; color:#334155; font-size:.75rem; }
        .empty { text-align:center; padding:30px; color:#64748b; }
        .backup-status { margin-top:16px; padding:14px; border-radius:10px; border:1px solid; display:grid; grid-template-columns:auto 1fr; gap:10px; align-items:start; }
        .backup-status strong { display:block; } .backup-status .meta { margin-top:4px; }
        .backup-status.ok { background:#ecfdf5; border-color:#86efac; color:#065f46; }
        .backup-status.warn { background:#fffbeb; border-color:#fcd34d; color:#92400e; }
        .backup-status.error { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
        html.nuvi-tema-oscuro .panel { background:#172033 !important; border-color:#334155 !important; }
        html.nuvi-tema-oscuro .intro, html.nuvi-tema-oscuro .muted, html.nuvi-tema-oscuro .meta, html.nuvi-tema-oscuro label { color:#cbd5e1 !important; }
        html.nuvi-tema-oscuro .btn-secondary, html.nuvi-tema-oscuro pre { background:#1e293b !important; color:#e2e8f0 !important; border-color:#475569 !important; }
        html.nuvi-tema-oscuro .notice { background:#3b2b12 !important; border-color:#b7791f !important; color:#fde68a !important; }
        html.nuvi-tema-oscuro .backup-status.ok { background:#123b2d !important; border-color:#2f855a !important; color:#bbf7d0 !important; }
        html.nuvi-tema-oscuro .backup-status.warn { background:#3b2b12 !important; border-color:#b7791f !important; color:#fde68a !important; }
        html.nuvi-tema-oscuro .backup-status.error { background:#3b1720 !important; border-color:#b91c1c !important; color:#fecaca !important; }
        @media(max-width:640px) { body { padding:10px; } .panel { padding:16px; } .field { flex:1 1 100%; } .filters button,.filters a { flex:1; justify-content:center; } }
    </style>
</head>
<body>
<?php renderAppHeader('bitacora', true); ?>
<main>
    <section class="panel">
        <h1>🧾 Bitácora operativa</h1>
        <p class="intro">Registro de cambios importantes. Las ventas, ajustes y movimientos siguen funcionando aunque la bitácora no esté disponible.</p>
        <?php
        $claseRespaldo = $estadoRespaldo['estado'] === 'correcto'
            ? 'ok'
            : (in_array($estadoRespaldo['estado'], ['error', 'vencido'], true) ? 'error' : 'warn');
        $tituloRespaldo = $estadoRespaldo['estado'] === 'correcto'
            ? 'Respaldo verificado'
            : ($estadoRespaldo['estado'] === 'vencido' ? 'Respaldo vencido' : ($estadoRespaldo['estado'] === 'error' ? 'Falló el respaldo' : 'Respaldo pendiente de configurar'));
        ?>
        <div class="backup-status <?php echo $claseRespaldo; ?>">
            <span aria-hidden="true"><?php echo $claseRespaldo === 'ok' ? '✓' : ($claseRespaldo === 'error' ? '⚠' : '⏳'); ?></span>
            <div>
                <strong><?php echo $tituloRespaldo; ?></strong>
                <div><?php echo htmlspecialchars($estadoRespaldo['mensaje']); ?></div>
                <?php if ($estadoRespaldo['fecha']): ?><div class="meta">Último estado: <?php echo htmlspecialchars($estadoRespaldo['fecha']); ?> · hace <?php echo (int) $estadoRespaldo['edad_horas']; ?> h</div><?php endif; ?>
            </div>
        </div>
        <?php if (!$disponible): ?>
            <div class="notice" style="margin-top:16px;"><strong>Falta preparar la bitácora.</strong> Entra a Configurar terminales y usa “Preparar terminales ahora”. Es una migración segura: crea la tabla sin modificar ventas ni inventario.</div>
        <?php else: ?>
            <form class="filters" method="get">
                <div class="field"><label for="accion">Acción</label><select id="accion" name="accion"><option value="">Todas</option><?php foreach ($accionesPermitidas as $opcion): ?><option value="<?php echo htmlspecialchars($opcion); ?>" <?php echo $accion === $opcion ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $opcion)); ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="entidad">Área</label><select id="entidad" name="entidad"><option value="">Todas</option><?php foreach ($entidadesPermitidas as $opcion): ?><option value="<?php echo htmlspecialchars($opcion); ?>" <?php echo $entidad === $opcion ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($opcion)); ?></option><?php endforeach; ?></select></div>
                <button type="submit">Filtrar</button>
                <a class="btn-secondary" href="bitacora.php">Limpiar</a>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($disponible): ?>
    <section class="panel">
        <div class="muted" style="margin-bottom:12px;">Mostrando hasta los últimos 250 movimientos, del más reciente al más antiguo.</div>
        <div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th><th>Datos</th></tr></thead><tbody>
        <?php if (!$registros): ?><tr><td colspan="5" class="empty">Aún no hay movimientos con estos filtros.</td></tr><?php endif; ?>
        <?php foreach ($registros as $registro): $detalles = nuviBitacoraDetalles((string) ($registro['detalles_json'] ?? '')); ?>
            <tr>
                <td><strong><?php echo htmlspecialchars(date('d/m/Y', strtotime($registro['fecha']))); ?></strong><div class="meta"><?php echo htmlspecialchars(date('H:i:s', strtotime($registro['fecha']))); ?></div></td>
                <td><?php echo htmlspecialchars((string) $registro['usuario_nombre']); ?><div class="meta"><?php echo $registro['id_usuario'] ? 'ID ' . (int) $registro['id_usuario'] : 'Sistema'; ?></div></td>
                <td><span class="tag"><?php echo htmlspecialchars(str_replace('_', ' ', (string) $registro['accion'])); ?></span><div class="meta"><?php echo htmlspecialchars((string) $registro['entidad']); ?><?php echo $registro['entidad_id'] ? ' #' . (int) $registro['entidad_id'] : ''; ?></div></td>
                <td><?php echo htmlspecialchars((string) $registro['descripcion']); ?></td>
                <td><?php if ($detalles !== ''): ?><details><summary>Ver</summary><pre><?php echo htmlspecialchars($detalles); ?></pre></details><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
