<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('conexion.php');
require_once('app_header.php');
require_once('operacion_inventario_utils.php');
require_once('abastecimiento_form_utils.php');

$rolActual = $_SESSION['rol'] ?? '';
$estructuraLista = isset($conexion) && $conexion instanceof mysqli && nuviOperacionInventarioListo($conexion);
$estado = trim((string) ($_GET['estado'] ?? ''));
$periodoUtilidad = (int) ($_GET['periodo'] ?? 30);
if (!in_array($periodoUtilidad, [7, 30, 90], true)) $periodoUtilidad = 30;
$mensajes = [
    'proveedor_guardado' => ['ok', 'Proveedor guardado.'],
    'proveedor_duplicado' => ['error', 'Ya existe un proveedor con ese nombre.'],
    'compra_guardada' => ['ok', 'Compra registrada: el stock, costo y lote se actualizaron.'],
    'merma_guardada' => ['ok', 'Merma registrada y descontada del inventario.'],
    'estructura_pendiente' => ['error', 'Primero prepara la estructura de abastecimiento.'],
    'compra_invalida' => ['error', 'Revisa productos, cantidades y costos de la compra.'],
    'merma_invalida' => ['error', 'Revisa el producto, la cantidad y el motivo de la merma.'],
    'fecha_invalida' => ['error', 'La fecha de compra no es válida.'],
    'caducidad_invalida' => ['error', 'Una fecha de caducidad no es válida.'],
    'sin_productos' => ['error', 'Agrega al menos un producto a la compra.'],
    'error_compra' => ['error', 'No se pudo registrar la compra; no se modificó el inventario.'],
    'error_merma' => ['error', 'No se pudo registrar la merma; no se modificó el inventario.'],
    'merma_stock' => ['error', 'La cantidad supera el stock actual. Revisa las existencias antes de registrar la merma.'],
    'formulario_vencido' => ['error', 'Este formulario ya se envió o venció. Revisa las compras, mermas o fechas registradas antes de volver a intentarlo.'],
    'caducidad_guardada' => ['ok', 'Fecha registrada para las existencias indicadas, sin sumar ni descontar stock.'],
    'caducidad_existente_invalida' => ['error', 'Revisa el producto, la cantidad y la fecha de caducidad.'],
    'caducidad_stock' => ['error', 'No se puede asignar esa cantidad: revisa el stock y las fechas ya registradas para el producto.'],
    'error_caducidad' => ['error', 'No se pudo registrar la fecha; no se modificaron las existencias.'],
    'proveedor_invalido' => ['error', 'Revisa los datos del proveedor.'],
    'correo_invalido' => ['error', 'El correo del proveedor no es válido.'],
    'error_proveedor' => ['error', 'No se pudo guardar el proveedor.'],
];

$proveedores = $productos = $proximos = $compras = $mermas = [];
$productosPorProveedor = [];
$errorHistorialProveedor = false;
$resumen = ['compras' => 0, 'mermas' => 0, 'lotes' => 0];
$utilidad = ['ventas' => 0, 'costo_ventas' => 0, 'utilidad_bruta' => 0, 'merma' => 0, 'utilidad_neta' => 0];
$utilidadCategorias = [];
if ($estructuraLista) {
    $condicionProductoActivo = nuviOperacionColumnaExiste($conexion, 'productos', 'activo')
        ? '(activo IS NULL OR activo = 1)'
        : '1 = 1';
    $res = mysqli_query($conexion, 'SELECT id, nombre, contacto, telefono FROM proveedores WHERE activo = 1 ORDER BY nombre ASC');
    while ($res && ($fila = mysqli_fetch_assoc($res))) $proveedores[] = $fila;

    $res = mysqli_query($conexion, "SELECT id, nombre, marca, codigo_barras, unidad_medida, stock, precio_compra
        FROM productos WHERE {$condicionProductoActivo} ORDER BY nombre ASC");
    while ($res && ($fila = mysqli_fetch_assoc($res))) $productos[] = $fila;

    try {
        $res = mysqli_query($conexion, 'SELECT DISTINCT c.id_proveedor, d.id_producto
            FROM compras c JOIN compra_detalles d ON d.id_compra = c.id
            WHERE c.id_proveedor IS NOT NULL');
    } catch (mysqli_sql_exception $e) {
        error_log('abastecimiento historial de proveedores: ' . $e->getMessage());
        $res = false;
    }
    if (!$res) {
        error_log('abastecimiento: no se pudo consultar el historial de proveedores.');
        $errorHistorialProveedor = true;
    } else {
        while ($fila = mysqli_fetch_assoc($res)) {
            $productosPorProveedor[(int) $fila['id_proveedor']][] = (int) $fila['id_producto'];
        }
    }

    try {
        $res = mysqli_query($conexion, "SELECT l.id, l.fecha_caducidad, l.cantidad_disponible, p.nombre, p.unidad_medida,
            DATEDIFF(l.fecha_caducidad, CURDATE()) AS dias
            FROM lotes_inventario l JOIN productos p ON p.id = l.id_producto
            WHERE l.cantidad_disponible > 0 AND l.fecha_caducidad IS NOT NULL
              AND l.fecha_caducidad <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY l.fecha_caducidad ASC, p.nombre ASC LIMIT 100");
    } catch (mysqli_sql_exception $e) {
        error_log('abastecimiento caducidades: ' . $e->getMessage());
        $res = false;
    }
    if (!$res) {
        error_log('abastecimiento: no se pudieron consultar caducidades.');
        $errorCaducidades = true;
    } else {
        $errorCaducidades = false;
        while ($fila = mysqli_fetch_assoc($res)) $proximos[] = $fila;
    }

    $res = mysqli_query($conexion, "SELECT c.id, c.fecha_compra, c.folio, c.total, COALESCE(p.nombre, 'Sin proveedor') AS proveedor
        FROM compras c LEFT JOIN proveedores p ON p.id = c.id_proveedor ORDER BY c.fecha_compra DESC, c.id DESC LIMIT 12");
    while ($res && ($fila = mysqli_fetch_assoc($res))) $compras[] = $fila;

    $res = mysqli_query($conexion, "SELECT m.fecha_movimiento, m.cantidad, m.costo_unitario, m.motivo, p.nombre
        FROM movimientos_inventario m JOIN productos p ON p.id = m.id_producto
        WHERE m.tipo = 'merma' ORDER BY m.fecha_movimiento DESC, m.id DESC LIMIT 12");
    while ($res && ($fila = mysqli_fetch_assoc($res))) $mermas[] = $fila;

    $res = mysqli_query($conexion, "SELECT
        COALESCE((SELECT SUM(total) FROM compras WHERE fecha_compra >= DATE_SUB(NOW(), INTERVAL {$periodoUtilidad} DAY)), 0) AS compras,
        COALESCE((SELECT SUM(ABS(cantidad) * costo_unitario) FROM movimientos_inventario WHERE tipo = 'merma' AND fecha_movimiento >= DATE_SUB(NOW(), INTERVAL {$periodoUtilidad} DAY)), 0) AS mermas,
        COALESCE((SELECT COUNT(*) FROM lotes_inventario WHERE cantidad_disponible > 0 AND fecha_caducidad IS NOT NULL), 0) AS lotes");
    $resumen = $res ? (mysqli_fetch_assoc($res) ?: $resumen) : $resumen;

    // Las ventas nuevas conservan costo_unitario al vender. En ventas previas
    // se usa el último costo disponible, por eso ese histórico es estimado.
    $tieneSubtotal = nuviOperacionColumnaExiste($conexion, 'detalle_ventas', 'subtotal');
    $importeVenta = $tieneSubtotal ? 'COALESCE(dv.subtotal, dv.cantidad * dv.precio_unitario)' : '(dv.cantidad * dv.precio_unitario)';
    $costoVenta = 'dv.cantidad * COALESCE(dv.costo_unitario, p.precio_compra, 0)';
    $condicionMetodo = nuviOperacionColumnaExiste($conexion, 'ventas', 'metodo_pago') ? " AND COALESCE(v.metodo_pago, '') <> 'devuelto'" : '';
    $sqlUtilidad = "SELECT
        COALESCE(SUM($importeVenta), 0) AS ventas,
        COALESCE(SUM($costoVenta), 0) AS costo_ventas
        FROM detalle_ventas dv
        JOIN ventas v ON v.id_venta = dv.id_venta
        LEFT JOIN productos p ON p.id = dv.id_producto
        WHERE v.fecha_venta >= DATE_SUB(NOW(), INTERVAL {$periodoUtilidad} DAY)$condicionMetodo";
    $res = mysqli_query($conexion, $sqlUtilidad);
    $utilidad = $res ? (mysqli_fetch_assoc($res) ?: $utilidad) : $utilidad;
    $utilidad['ventas'] = (float) $utilidad['ventas'];
    $utilidad['costo_ventas'] = (float) $utilidad['costo_ventas'];
    $utilidad['utilidad_bruta'] = $utilidad['ventas'] - $utilidad['costo_ventas'];
    $utilidad['merma'] = (float) $resumen['mermas'];
    $utilidad['utilidad_neta'] = $utilidad['utilidad_bruta'] - $utilidad['merma'];

    $sqlCategorias = "SELECT COALESCE(NULLIF(p.categoria, ''), 'Sin categoría') AS categoria,
        COALESCE(SUM($importeVenta), 0) AS ventas,
        COALESCE(SUM($costoVenta), 0) AS costo,
        COALESCE(SUM($importeVenta - $costoVenta), 0) AS utilidad
        FROM detalle_ventas dv JOIN ventas v ON v.id_venta = dv.id_venta
        LEFT JOIN productos p ON p.id = dv.id_producto
        WHERE v.fecha_venta >= DATE_SUB(NOW(), INTERVAL {$periodoUtilidad} DAY)$condicionMetodo
        GROUP BY COALESCE(NULLIF(p.categoria, ''), 'Sin categoría') ORDER BY utilidad DESC LIMIT 8";
    $res = mysqli_query($conexion, $sqlCategorias);
    while ($res && ($fila = mysqli_fetch_assoc($res))) $utilidadCategorias[] = $fila;
}

$opcionesProducto = '<option value="">Selecciona un producto…</option>';
foreach ($productos as $producto) {
    $texto = $producto['nombre'] . ' · Stock: ' . rtrim(rtrim(number_format((float) $producto['stock'], 3, '.', ''), '0'), '.') . ' ' . ($producto['unidad_medida'] ?: 'pza');
    $opcionesProducto .= '<option value="' . (int) $producto['id'] . '" data-costo="' . htmlspecialchars(number_format((float) $producto['precio_compra'], 4, '.', ''), ENT_QUOTES, 'UTF-8')
        . '" data-codigo="' . htmlspecialchars((string) $producto['codigo_barras'], ENT_QUOTES, 'UTF-8')
        . '" data-unidad="' . htmlspecialchars((string) ($producto['unidad_medida'] ?? ''), ENT_QUOTES, 'UTF-8')
        . '" data-marca="' . htmlspecialchars((string) ($producto['marca'] ?? ''), ENT_QUOTES, 'UTF-8')
        . '">' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</option>';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abastecimiento · Tienda Nuvi</title>
    <?php renderAppHeaderStyles(); ?>
    <style>
        body { margin:0; padding:10px; background:#f4f6f8; color:#1e293b; font-family:'Segoe UI',system-ui,sans-serif; }
        .contenedor { max-width:1280px; margin:auto; background:#fff; padding:28px; border-radius:16px; box-shadow:0 10px 25px rgba(15,23,42,.06); }
        h1 { margin:0 0 8px; } .subtitulo { color:#64748b; margin:0 0 24px; }
        .alerta { padding:12px 14px; border-radius:10px; margin:0 0 18px; font-weight:600; }
        .alerta.ok { background:#ecfdf5; color:#065f46; border:1px solid #86efac; } .alerta.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .resumen, .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
        .tarjeta { border:1px solid #e2e8f0; border-radius:13px; padding:17px; background:#f8fafc; } .tarjeta h2 { margin:0 0 12px; font-size:1.08rem; }
        .kpi strong { display:block; font-size:1.55rem; margin-top:5px; color:#0f766e; } .kpi span { color:#64748b; font-size:.88rem; }
        label { display:block; font-weight:700; margin:10px 0 5px; } input, select, textarea { width:100%; box-sizing:border-box; padding:10px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; background:#fff; color:#1e293b; }
        textarea { min-height:76px; resize:vertical; } button { border:0; border-radius:8px; padding:10px 14px; font:inherit; font-weight:750; cursor:pointer; background:#2563eb; color:#fff; } button.secundario { background:#e2e8f0; color:#1e293b; } button.danger { background:#dc2626; } button:disabled { opacity:.55; cursor:not-allowed; }
        .linea-compra { display:grid; grid-template-columns:minmax(190px,2fr) 100px 115px 145px 130px 42px; gap:8px; align-items:end; border-bottom:1px solid #e2e8f0; padding:10px 0; }
        .linea-compra label { margin:0 0 4px; font-size:.8rem; color:#64748b; } .acciones { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
        table { width:100%; border-collapse:collapse; font-size:.92rem; } th,td { padding:10px; text-align:left; border-bottom:1px solid #e2e8f0; } th { color:#475569; font-size:.78rem; text-transform:uppercase; }
        .scroll { overflow:auto; } .vencido { color:#b91c1c; font-weight:750; } .proximo { color:#b45309; font-weight:750; }
        .preparar { max-width:700px; margin:28px auto; text-align:center; padding:30px; border:1px solid #93c5fd; border-radius:16px; background:#eff6ff; }
        .motivos { display:flex; flex-wrap:wrap; gap:8px; }
        .motivos button { background:#e2e8f0; color:#1e293b; border:2px solid #94a3b8; }
        .motivos button[aria-pressed="true"] { background:#1d4ed8; color:#fff; border-color:#1d4ed8; }
        @media(max-width:780px) { .contenedor { padding:18px; } .linea-compra { grid-template-columns:1fr 1fr; } .linea-compra > :first-child { grid-column:1 / -1; } .linea-compra .quitar-linea { grid-column:2; } }
        html.nuvi-tema-oscuro .tarjeta, html.nuvi-tema-oscuro .preparar { background:#172033!important; color:#e2e8f0!important; border-color:#334155!important; }
        html.nuvi-tema-oscuro .subtitulo, html.nuvi-tema-oscuro .kpi span, html.nuvi-tema-oscuro .linea-compra label, html.nuvi-tema-oscuro th { color:#cbd5e1!important; }
        html.nuvi-tema-oscuro .kpi strong { color:#6ee7b7!important; } html.nuvi-tema-oscuro button.secundario { background:#334155; color:#f8fafc; } html.nuvi-tema-oscuro td, html.nuvi-tema-oscuro th { border-color:#334155; }
        html.nuvi-tema-oscuro .motivos button { background:#334155!important; color:#f8fafc!important; border-color:#94a3b8!important; }
        html.nuvi-tema-oscuro .motivos button[aria-pressed="true"] { background:#1d4ed8!important; color:#fff!important; border-color:#93c5fd!important; }
        html.nuvi-tema-oscuro .vencido { color:#fca5a5!important; }
        html.nuvi-tema-oscuro .proximo { color:#fcd34d!important; }
    </style>
</head>
<body>
<?php renderAppHeader('abastecimiento'); ?>
<main class="contenedor">
    <h1>📥 Abastecimiento e inventario</h1>
    <p class="subtitulo">Registra compras, proveedores, lotes y merma. El inventario se mueve sólo al guardar una operación válida.</p>
    <?php if (isset($mensajes[$estado])): $mensaje = $mensajes[$estado]; ?>
        <div class="alerta <?php echo $mensaje[0]; ?>"><?php echo htmlspecialchars($mensaje[1]); ?></div>
    <?php endif; ?>

    <?php if (!$estructuraLista): ?>
        <section class="preparar">
            <h2>Preparar abastecimiento</h2>
            <p>Agrega tablas nuevas para proveedores, compras, lotes, merma y costo histórico. No borra ni modifica ventas o productos existentes.</p>
            <?php if ($rolActual === 'admin'): ?>
                <button id="preparar-estructura" type="button">Preparar estructura ahora</button>
                <p id="resultado-estructura" aria-live="polite"></p>
            <?php else: ?>
                <p>Un administrador debe preparar la estructura una sola vez.</p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="tarjeta" aria-labelledby="titulo-caducidad">
            <h2 id="titulo-caducidad">⏳ Revisar caducidades · próximos 30 días</h2>
            <p>Solo aparecen lotes con fecha registrada. Los demás productos también necesitan revisión física.</p>
            <div class="scroll"><table><thead><tr><th>Producto</th><th>Fecha</th><th>Disponible registrado</th><th>Estado</th></tr></thead><tbody>
                <?php if ($errorCaducidades): ?><tr><td colspan="4">No se pudieron consultar las caducidades. Revisa la conexión e inténtalo de nuevo.</td></tr>
                <?php elseif (!$proximos): ?><tr><td colspan="4">No hay lotes registrados próximos a caducar.</td></tr>
                <?php else: foreach ($proximos as $lote): $dias = (int) $lote['dias']; ?>
                    <tr><td><?php echo htmlspecialchars($lote['nombre']); ?></td><td><?php echo htmlspecialchars($lote['fecha_caducidad']); ?></td><td><?php echo rtrim(rtrim(number_format((float) $lote['cantidad_disponible'], 3, '.', ''), '0'), '.') . ' ' . htmlspecialchars($lote['unidad_medida'] ?: 'pza'); ?></td><td class="<?php echo $dias < 0 ? 'vencido' : 'proximo'; ?>"><?php echo $dias < 0 ? 'Caducado' : ($dias === 0 ? 'Caduca hoy' : 'En ' . $dias . ' días'); ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
            <?php if (count($proximos) === 100): ?><p>Se muestran los primeros 100 lotes, ordenados por fecha.</p><?php endif; ?>
        </section>
        <section class="tarjeta" style="margin-top:18px;">
            <h2>🧾 Registrar compra / entrada de mercancía</h2>
            <form method="post" action="registrar_compra.php" id="form-compra">
                <input type="hidden" name="token_operacion" value="<?php echo htmlspecialchars(nuviCrearTokenAbastecimiento('compra'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="grid">
                    <div><label>Proveedor</label><select name="id_proveedor" id="proveedor-compra"><option value="">Sin proveedor / compra directa</option><?php foreach($proveedores as $proveedor): ?><option value="<?php echo (int)$proveedor['id']; ?>"><?php echo htmlspecialchars($proveedor['nombre']); ?></option><?php endforeach; ?></select></div>
                    <div><label>Fecha y hora</label><input type="datetime-local" name="fecha_compra" value="<?php echo date('Y-m-d\TH:i'); ?>"></div>
                    <div><label>Folio o factura</label><input name="folio" maxlength="80" placeholder="Opcional"></div>
                </div>
                <label for="marca-compra">Filtrar lista por marca / proveedor habitual</label>
                <select id="marca-compra"><option value="">Todas las marcas</option></select>
                <small>Este filtro usa la marca registrada en el producto; no cambia el proveedor de la compra.</small>
                <label><input type="checkbox" id="solo-historial" style="width:auto" <?php echo $errorHistorialProveedor ? 'disabled' : ''; ?>> Mostrar productos comprados antes a este proveedor</label>
                <small id="aviso-historial"><?php echo $errorHistorialProveedor ? 'No se pudo consultar el historial; aún puedes buscar todos los productos.' : 'La lista se basa solo en compras registradas; no representa todos los productos que entrega.'; ?></small>
                <label for="codigo-compra">Escanear producto</label>
                <input id="codigo-compra" type="text" autocomplete="off" placeholder="Escanea y pulsa Enter">
                <p id="mensaje-codigo-compra" role="status"></p>
                <div id="lineas-compra"></div>
                <template id="plantilla-linea-compra">
                    <div class="linea-compra">
                        <div><label>Producto *</label><select name="producto_id[]" required><?php echo $opcionesProducto; ?></select></div>
                        <div><label>Cantidad *</label><input type="number" name="cantidad[]" min="0.001" step="0.001" required></div>
                        <div><label>Costo unit. *</label><input type="number" name="costo_unitario[]" min="0" step="0.0001" required></div>
                        <div><label>Caducidad</label><input type="date" name="fecha_caducidad[]"></div>
                        <div><label>Lote</label><input name="lote[]" maxlength="80" placeholder="Opcional"></div>
                        <button class="danger quitar-linea" type="button" title="Quitar línea">✕</button>
                    </div>
                </template>
                <div class="acciones"><button type="button" class="secundario" id="agregar-linea">＋ Agregar producto</button><button>Guardar compra y actualizar stock</button></div>
                <label>Notas de compra</label><textarea name="notas" maxlength="500" placeholder="Opcional: condiciones, faltantes, observaciones…"></textarea>
            </form>
        </section>

        <section class="grid" style="margin-top:18px;">
            <form class="tarjeta" method="post" action="registrar_merma.php" id="form-merma">
                <input type="hidden" name="token_operacion" value="<?php echo htmlspecialchars(nuviCrearTokenAbastecimiento('merma'), ENT_QUOTES, 'UTF-8'); ?>">
                <h2>🗑️ Registrar merma</h2>
                <label for="codigo-merma">Escanear código</label>
                <input id="codigo-merma" type="text" autocomplete="off" placeholder="Escanea y pulsa Enter">
                <p id="mensaje-codigo-merma" role="status"></p>
                <label>Producto *</label><select name="id_producto" required><?php echo $opcionesProducto; ?></select>
                <label>Cantidad a descontar *</label><input type="number" name="cantidad" min="0.001" step="0.001" required>
                <label>Motivo *</label>
                <div class="motivos" aria-label="Motivos frecuentes">
                    <button type="button" data-motivo="Caducado" aria-pressed="false">Caducado</button>
                    <button type="button" data-motivo="Con hongos" aria-pressed="false">Con hongos</button>
                    <button type="button" data-motivo="Dañado" aria-pressed="false">Dañado</button>
                    <button type="button" data-motivo="Derrame" aria-pressed="false">Derrame</button>
                </div>
                <textarea name="motivo" maxlength="255" placeholder="Elige un motivo o escribe otro…" required></textarea>
                <div class="acciones"><button class="danger">Registrar merma</button></div>
            </form>
            <form class="tarjeta" method="post" action="guardar_proveedor.php">
                <h2>🤝 Nuevo proveedor</h2>
                <label>Nombre *</label><input name="nombre" maxlength="120" required>
                <label>Contacto</label><input name="contacto" maxlength="120">
                <label>Teléfono</label><input name="telefono" maxlength="40">
                <label>Correo</label><input type="email" name="email" maxlength="150">
                <label>Notas</label><textarea name="notas" maxlength="500"></textarea>
                <div class="acciones"><button>Guardar proveedor</button></div>
            </form>
        </section>

        <section class="tarjeta" id="registrar-caducidad" style="margin-top:18px;">
            <h2>📅 Anotar fecha de mercancía existente</h2>
            <p>Cuenta físicamente las unidades que tienen la misma fecha. Esto no agrega mercancía ni cambia el stock.</p>
            <form method="post" action="registrar_caducidad.php" id="form-caducidad">
                <input type="hidden" name="token_operacion" value="<?php echo htmlspecialchars(nuviCrearTokenAbastecimiento('caducidad'), ENT_QUOTES, 'UTF-8'); ?>">
                <label for="codigo-caducidad">Escanear código</label>
                <input id="codigo-caducidad" type="text" autocomplete="off" placeholder="Escanea o busca en la lista">
                <small id="mensaje-codigo-caducidad" role="status"></small>
                <label>Producto *</label><select name="id_producto" required><?php echo $opcionesProducto; ?></select>
                <div class="campos">
                    <div><label>Cantidad con esta fecha *</label><input type="number" name="cantidad" step="0.001" min="0.001" required></div>
                    <div><label>Fecha de caducidad *</label><input type="date" name="fecha_caducidad" required></div>
                </div>
                <small>No vuelvas a capturar unidades que ya tengan una fecha registrada. Si hay fechas diferentes, registra cada cantidad por separado.</small>
                <div class="acciones"><button>Guardar fecha</button></div>
            </form>
        </section>

        <section class="tarjeta" style="margin-top:18px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;"><div><h2 style="margin-bottom:4px;">📈 Utilidad estimada</h2><p style="margin:0;color:#64748b;font-size:.9rem;">Desde hoy las ventas conservan su costo unitario. El historial anterior se estima con el último costo registrado.</p></div><form method="get"><label style="margin:0 0 4px;">Periodo</label><select name="periodo" onchange="this.form.submit()"><option value="7" <?php echo $periodoUtilidad===7?'selected':''; ?>>7 días</option><option value="30" <?php echo $periodoUtilidad===30?'selected':''; ?>>30 días</option><option value="90" <?php echo $periodoUtilidad===90?'selected':''; ?>>90 días</option></select></form></div>
            <div class="resumen" style="margin-top:14px;"><div class="kpi"><span>Ventas de productos</span><strong>$<?php echo number_format($utilidad['ventas'],2); ?></strong></div><div class="kpi"><span>Costo de lo vendido</span><strong>$<?php echo number_format($utilidad['costo_ventas'],2); ?></strong></div><div class="kpi"><span>Utilidad bruta</span><strong>$<?php echo number_format($utilidad['utilidad_bruta'],2); ?></strong></div><div class="kpi"><span>Después de merma</span><strong>$<?php echo number_format($utilidad['utilidad_neta'],2); ?></strong></div></div>
            <div class="scroll" style="margin-top:14px;"><table><thead><tr><th>Categoría</th><th>Ventas</th><th>Costo</th><th>Utilidad</th></tr></thead><tbody><?php if(!$utilidadCategorias): ?><tr><td colspan="4">Aún no hay ventas en el periodo.</td></tr><?php else: foreach($utilidadCategorias as $categoria): ?><tr><td><?php echo htmlspecialchars($categoria['categoria']); ?></td><td>$<?php echo number_format((float)$categoria['ventas'],2); ?></td><td>$<?php echo number_format((float)$categoria['costo'],2); ?></td><td>$<?php echo number_format((float)$categoria['utilidad'],2); ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
        </section>
        <section class="resumen" style="margin-top:18px;">
            <div class="tarjeta kpi"><span>Compras últimos <?php echo $periodoUtilidad; ?> días</span><strong>$<?php echo number_format((float)$resumen['compras'], 2); ?></strong></div>
            <div class="tarjeta kpi"><span>Merma al costo · <?php echo $periodoUtilidad; ?> días</span><strong>$<?php echo number_format((float)$resumen['mermas'], 2); ?></strong></div>
            <div class="tarjeta kpi"><span>Lotes con caducidad registrada</span><strong><?php echo (int)$resumen['lotes']; ?></strong></div>
        </section>

        <section class="grid" style="margin-top:18px;">
            <div class="tarjeta"><h2>🗑️ Últimas mermas</h2><div class="scroll"><table><thead><tr><th>Fecha</th><th>Producto</th><th>Cant.</th><th>Motivo</th></tr></thead><tbody><?php if(!$mermas): ?><tr><td colspan="4">Aún no hay mermas registradas.</td></tr><?php else: foreach($mermas as $merma): ?><tr><td><?php echo htmlspecialchars($merma['fecha_movimiento']); ?></td><td><?php echo htmlspecialchars($merma['nombre']); ?></td><td><?php echo number_format(abs((float)$merma['cantidad']),3); ?></td><td><?php echo htmlspecialchars($merma['motivo']); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div>
        </section>
        <section class="tarjeta" style="margin-top:18px;"><h2>🕘 Compras recientes</h2><div class="scroll"><table><thead><tr><th>Fecha</th><th>Proveedor</th><th>Folio</th><th>Total</th></tr></thead><tbody><?php if(!$compras): ?><tr><td colspan="4">Aún no hay compras registradas.</td></tr><?php else: foreach($compras as $compra): ?><tr><td><?php echo htmlspecialchars($compra['fecha_compra']); ?></td><td><?php echo htmlspecialchars($compra['proveedor']); ?></td><td><?php echo htmlspecialchars($compra['folio'] ?: '—'); ?></td><td>$<?php echo number_format((float)$compra['total'],2); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
    <?php endif; ?>
</main>
<script>
(() => {
    const historialProveedor = <?php echo json_encode($productosPorProveedor, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const plantilla = document.getElementById('plantilla-linea-compra');
    const lineas = document.getElementById('lineas-compra');
    const proveedor = document.getElementById('proveedor-compra');
    const soloHistorial = document.getElementById('solo-historial');
    const prepararLinea = linea => {
        const selector = linea.querySelector('select[name="producto_id[]"]');
        const costo = linea.querySelector('input[name="costo_unitario[]"]');
        selector?.addEventListener('change', () => {
            if (costo) costo.value = selector.selectedOptions[0]?.dataset.costo || '';
            filtrarMarcas(linea);
        });
        linea.querySelector('.quitar-linea').addEventListener('click', event => {
            if (lineas.children.length <= 1) return;
            event.currentTarget.closest('.linea-compra').remove();
        });
    };
    const agregar = () => {
        if (!plantilla || !lineas) return;
        const fragmento = plantilla.content.cloneNode(true);
        const linea = fragmento.querySelector('.linea-compra');
        prepararLinea(linea);
        lineas.appendChild(fragmento);
        filtrarMarcas(linea);
        return linea;
    };
    const marca = document.getElementById('marca-compra');
    const filtrarMarcas = contenedor => {
        if (!marca || !contenedor) return;
        const ids = historialProveedor[proveedor?.value] || [];
        contenedor.querySelectorAll('select[name="producto_id[]"] option').forEach(opcion => {
            const otraMarca = marca.value && (opcion.dataset.marca || '').trim() !== marca.value;
            const fueraHistorial = soloHistorial?.checked && proveedor?.value && !ids.includes(Number(opcion.value));
            opcion.hidden = Boolean(opcion.value && !opcion.selected && (otraMarca || fueraHistorial));
        });
    };
    if (plantilla && marca) {
        const marcas = new Set(Array.from(plantilla.content.querySelectorAll('option[data-marca]'))
            .map(opcion => opcion.dataset.marca.trim()).filter(Boolean));
        Array.from(marcas).sort((a, b) => a.localeCompare(b, 'es')).forEach(nombre => {
            const opcion = document.createElement('option');
            opcion.value = nombre;
            opcion.textContent = nombre;
            marca.append(opcion);
        });
        marca.addEventListener('change', () => filtrarMarcas(lineas));
    }
    const actualizarHistorial = () => {
        const aviso = document.getElementById('aviso-historial');
        if (soloHistorial?.disabled) return;
        if (soloHistorial?.checked && !proveedor?.value) {
            aviso.textContent = 'Elige un proveedor para filtrar; mientras tanto se muestran todos los productos.';
        } else if (soloHistorial?.checked && !(historialProveedor[proveedor.value] || []).length) {
            aviso.textContent = 'Este proveedor no tiene productos en compras registradas. Desmarca el filtro para buscarlos todos.';
        } else {
            aviso.textContent = 'La lista se basa solo en compras registradas; no representa todos los productos que entrega.';
        }
        filtrarMarcas(lineas);
    };
    proveedor?.addEventListener('change', actualizarHistorial);
    soloHistorial?.addEventListener('change', actualizarHistorial);
    const buscarCodigo = (selector, codigo) => Array.from(selector.options)
        .find(opcion => opcion.value && opcion.dataset.codigo === codigo);
    document.getElementById('codigo-compra')?.addEventListener('keydown', event => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const codigo = event.currentTarget.value.trim();
        const selectorPlantilla = plantilla.content.querySelector('select');
        const producto = buscarCodigo(selectorPlantilla, codigo);
        const mensaje = document.getElementById('mensaje-codigo-compra');
        if (!producto) {
            mensaje.textContent = 'Código no encontrado. Busca el producto en la lista.';
            return;
        }
        let linea = Array.from(lineas.children).find(elemento => !elemento.querySelector('select').value);
        if (!linea) linea = agregar();
        const selector = linea.querySelector('select');
        selector.value = producto.value;
        selector.dispatchEvent(new Event('change'));
        filtrarMarcas(linea);
        mensaje.textContent = `Producto seleccionado: ${producto.textContent}. Escribe la cantidad recibida.`;
        event.currentTarget.value = '';
        linea.querySelector('input[name="cantidad[]"]').focus();
    });
    document.getElementById('codigo-merma')?.addEventListener('keydown', event => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const formulario = event.currentTarget.form;
        const selector = formulario.querySelector('select[name="id_producto"]');
        const producto = buscarCodigo(selector, event.currentTarget.value.trim());
        const mensaje = document.getElementById('mensaje-codigo-merma');
        if (!producto) {
            mensaje.textContent = 'Código no encontrado. Busca el producto en la lista.';
            return;
        }
        selector.value = producto.value;
        mensaje.textContent = `Producto seleccionado: ${producto.textContent}. Escribe la cantidad.`;
        event.currentTarget.value = '';
        formulario.querySelector('input[name="cantidad"]').focus();
    });
    document.getElementById('codigo-caducidad')?.addEventListener('keydown', event => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const formulario = event.currentTarget.form;
        const selector = formulario.querySelector('select[name="id_producto"]');
        const producto = buscarCodigo(selector, event.currentTarget.value.trim());
        const mensaje = document.getElementById('mensaje-codigo-caducidad');
        if (!producto) {
            mensaje.textContent = 'Código no encontrado. Busca el producto en la lista.';
            return;
        }
        selector.value = producto.value;
        mensaje.textContent = `Producto seleccionado: ${producto.textContent}. Cuenta la cantidad con la misma fecha.`;
        event.currentTarget.value = '';
        formulario.querySelector('input[name="cantidad"]').focus();
    });
    document.getElementById('agregar-linea')?.addEventListener('click', agregar);
    if (plantilla) agregar();

    const formularioMerma = document.getElementById('form-merma');
    const motivo = formularioMerma?.querySelector('[name="motivo"]');
    const botonesMotivo = formularioMerma?.querySelectorAll('[data-motivo]');
    const actualizarMotivos = () => botonesMotivo?.forEach(boton => {
        boton.setAttribute('aria-pressed', String(boton.dataset.motivo === motivo.value));
    });
    botonesMotivo?.forEach(boton => boton.addEventListener('click', () => {
        motivo.value = boton.dataset.motivo;
        actualizarMotivos();
    }));
    motivo?.addEventListener('input', actualizarMotivos);
    formularioMerma?.addEventListener('submit', event => {
        const selector = formularioMerma.querySelector('[name="id_producto"]');
        const cantidad = formularioMerma.querySelector('[name="cantidad"]').value;
        const producto = selector.selectedOptions[0];
        const unidad = producto.dataset.unidad || 'pza';
        if (!confirm(`¿Descontar ${cantidad} ${unidad} de ${producto.textContent}?\nMotivo: ${motivo.value}\n\nRevisa la cantidad antes de guardar.`)) {
            event.preventDefault();
        }
    });
    const formularioCaducidad = document.getElementById('form-caducidad');
    formularioCaducidad?.addEventListener('submit', event => {
        const producto = formularioCaducidad.querySelector('[name="id_producto"]').selectedOptions[0];
        const cantidad = formularioCaducidad.querySelector('[name="cantidad"]').value;
        const fecha = formularioCaducidad.querySelector('[name="fecha_caducidad"]').value;
        if (!confirm(`¿Registrar ${cantidad} ${producto.dataset.unidad || 'pza'} de ${producto.textContent} con caducidad ${fecha}?\n\nEsto no cambia el stock.`)) {
            event.preventDefault();
        }
    });
    document.querySelectorAll('#form-compra, #form-merma, #form-caducidad').forEach(formulario => {
        formulario.addEventListener('submit', event => {
            if (event.defaultPrevented) return;
            formulario.querySelectorAll('button[type="submit"], .acciones button:not([type])').forEach(boton => {
                boton.disabled = true;
                boton.textContent = 'Guardando…';
            });
        });
    });

    document.getElementById('preparar-estructura')?.addEventListener('click', async event => {
        const boton = event.currentTarget;
        const resultado = document.getElementById('resultado-estructura');
        boton.disabled = true; resultado.textContent = 'Preparando estructura…';
        try {
            const respuesta = await fetch('migrate_operacion_inventario.php', { cache: 'no-store' });
            const datos = await respuesta.json();
            if (!respuesta.ok || !datos.success) throw new Error(datos.error || 'No se pudo preparar la estructura.');
            resultado.textContent = '✓ ' + datos.message + ' Recargando…';
            setTimeout(() => location.reload(), 700);
        } catch (error) {
            resultado.textContent = '✕ ' + error.message;
            boton.disabled = false;
        }
    });
})();
</script>
</body>
</html>
