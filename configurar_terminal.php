<?php
require_once('auth.php');
tienePermiso(['admin']);
require_once('conexion.php');
require_once('app_header.php');
require_once('comisiones_terminal.php');
require_once('auditoria_utils.php');
require_once('impresion_ticket_utils.php');
require_once('ventas_utils.php');

function nuviConfigTienePrecisionDecimal(mysqli $conexion, string $tabla, string $columna, int $escalaMinima): bool
{
    $res = mysqli_query($conexion, "SHOW COLUMNS FROM `$tabla` LIKE '$columna'");
    $definicion = $res ? mysqli_fetch_assoc($res) : null;
    $tipo = strtolower((string) ($definicion['Type'] ?? ''));
    return preg_match('/decimal\(\d+,\s*(\d+)\)/', $tipo, $coincidencia) === 1
        && (int) $coincidencia[1] >= $escalaMinima;
}

function nuviConfigTieneInteresSimple(mysqli $conexion): bool
{
    foreach (['fecha_inicio_interes', 'saldo_base_interes', 'semanas_interes_aplicadas'] as $columna) {
        $res = mysqli_query($conexion, "SHOW COLUMNS FROM clientes LIKE '$columna'");
        if (!$res || mysqli_num_rows($res) === 0) {
            return false;
        }
    }
    return true;
}

$esquemaListo = isset($conexion) && $conexion instanceof mysqli && nuviEsquemaComisionesListo($conexion);
$idempotenciaLista = isset($conexion) && $conexion instanceof mysqli && nuviVentasSoportanIdempotencia($conexion);
$bitacoraLista = isset($conexion) && $conexion instanceof mysqli && nuviAuditoriaDisponible($conexion);
$granelListo = isset($conexion) && $conexion instanceof mysqli
    && nuviConfigTienePrecisionDecimal($conexion, 'productos', 'stock', 3)
    && nuviConfigTienePrecisionDecimal($conexion, 'detalle_ventas', 'cantidad', 3)
    && nuviConfigTienePrecisionDecimal($conexion, 'productos', 'precio_venta', 4)
    && nuviConfigTienePrecisionDecimal($conexion, 'detalle_ventas', 'precio_unitario', 4);
$interesSimpleListo = isset($conexion) && $conexion instanceof mysqli && nuviConfigTieneInteresSimple($conexion);
$terminales = $esquemaListo ? nuviObtenerTerminalesPago($conexion, false) : [];
$configImpresora = isset($conexion) && $conexion instanceof mysqli
    ? nuviObtenerConfigImpresora($conexion)
    : nuviImpresionConfigPredeterminada();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terminales de tarjeta | Tienda Nuvi</title>
    <?php renderAppHeaderStyles(); ?>
    <style>
        body { margin:0; padding:16px; background:#f4f6f8; color:#172033; font-family:Segoe UI,system-ui,sans-serif; }
        main { max-width:1000px; margin:0 auto; }
        .panel { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:24px; box-shadow:0 10px 24px rgba(15,23,42,.06); margin-bottom:18px; }
        h1,h2 { margin:0 0 8px; } h1 { font-size:1.55rem; } h2 { font-size:1.1rem; }
        .intro,.muted { color:#526176; line-height:1.5; } .intro { margin:0; }
        .notice { padding:14px; border-radius:10px; margin-top:16px; line-height:1.45; }
        .warning { color:#92400e; background:#fffbeb; border:1px solid #fcd34d; } .success { color:#166534; background:#ecfdf5; border:1px solid #86efac; }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        label { display:block; font-weight:700; color:#334155; margin-bottom:6px; }
        input { width:100%; box-sizing:border-box; padding:10px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; }
        input:focus { outline:3px solid #bfdbfe; border-color:#2563eb; }
        .check { display:flex; gap:8px; align-items:center; margin-top:10px; font-weight:600; } .check input { width:auto; }
        .actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; }
        button { border:0; border-radius:8px; padding:10px 14px; color:white; background:#2563eb; font:inherit; font-weight:700; cursor:pointer; }
        button:hover { background:#1d4ed8; } button.secondary { background:#64748b; } button.warn { background:#b45309; } button.danger { background:#b91c1c; }
        button:disabled { opacity:.6; cursor:wait; }
        table { width:100%; border-collapse:collapse; } th,td { padding:12px 10px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; } th { color:#64748b; font-size:.8rem; text-transform:uppercase; }
        .pill { display:inline-block; border-radius:999px; padding:4px 8px; font-size:.78rem; font-weight:700; background:#dbeafe; color:#1d4ed8; } .pill.off { background:#e2e8f0; color:#475569; }
        .table-wrap { overflow:auto; } #mensaje { min-height:1.3rem; margin-top:10px; font-weight:700; } #mensaje.error { color:#b91c1c; } #mensaje.ok { color:#15803d; }
        .ejemplo { margin-top:15px; padding:13px; border-radius:10px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; }
        @media(max-width:620px) { body { padding:10px; } .panel { padding:17px; } .grid { grid-template-columns:1fr; } table { min-width:670px; } }

        /* Esta pantalla tiene estilos propios, por eso el tema global no
           alcanza sus tarjetas. Se definen contrastes explícitos para que el
           ejemplo y los importes no queden blancos sobre fondo claro. */
        html.nuvi-tema-oscuro body { background:#0f172a; color:#e2e8f0; }
        html.nuvi-tema-oscuro .panel { background:#172033; border-color:#334155; box-shadow:0 10px 24px rgba(0,0,0,.28); }
        html.nuvi-tema-oscuro h1,
        html.nuvi-tema-oscuro h2,
        html.nuvi-tema-oscuro label { color:#f8fafc; }
        html.nuvi-tema-oscuro .intro,
        html.nuvi-tema-oscuro .muted,
        html.nuvi-tema-oscuro th { color:#cbd5e1; }
        html.nuvi-tema-oscuro input { background:#0f172a; color:#f8fafc; border-color:#475569; }
        html.nuvi-tema-oscuro input:focus { outline-color:#2563eb; border-color:#60a5fa; }
        html.nuvi-tema-oscuro th,
        html.nuvi-tema-oscuro td { border-color:#334155; }
        html.nuvi-tema-oscuro .pill { background:#1d3557; color:#bfdbfe; }
        html.nuvi-tema-oscuro .pill.off { background:#334155; color:#e2e8f0; }
        html.nuvi-tema-oscuro .ejemplo { background:#1d3557; border-color:#3b82f6; color:#dbeafe; }
        html.nuvi-tema-oscuro .ejemplo strong { color:#ffffff; }
        html.nuvi-tema-oscuro .warning { background:#3b2b12; border-color:#b7791f; color:#fde68a; }
        html.nuvi-tema-oscuro .success { background:#12332b; border-color:#248567; color:#d1fae5; }
    </style>
</head>
<body>
    <?php renderAppHeader('terminales', true); ?>
    <main>
        <section class="panel">
            <h1>💳 Terminales de tarjeta</h1>
            <p class="intro">Configura cada terminal una sola vez. En el punto de venta el cajero sólo elige cuál usará y Nuvi calcula el cargo que debe cubrir el cliente.</p>
            <?php if (!$esquemaListo || !$idempotenciaLista): ?>
                <div class="notice warning"><strong>Falta preparar la base de datos.</strong> La migración conserva la configuración anterior como una terminal inicial y habilita la protección contra reintentos duplicados; no altera ventas ni inventario existentes.
                    <div class="actions"><button id="migrar" class="warn" type="button">Preparar terminales e interés simple</button></div>
                </div>
            <?php elseif (!$bitacoraLista || !$granelListo || !$interesSimpleListo): ?>
                <div class="notice warning"><strong>Hay una actualización pendiente.</strong> Prepara la bitácora, los ajustes seguros, la precisión para ventas a granel y el interés simple de libreta. No modifica ventas ni inventario existentes; sólo agrega la estructura necesaria y toma los saldos actuales como punto de inicio.
                    <div class="actions"><button id="migrar" class="warn" type="button">Activar interés simple y actualizar estructura</button></div>
                </div>
            <?php else: ?>
                <div class="notice success"><strong>Listo.</strong> La terminal marcada como predeterminada aparece seleccionada al cobrar.</div>
            <?php endif; ?>
        </section>

        <section class="panel" id="panel-formulario" <?php echo $esquemaListo ? '' : 'hidden'; ?>>
            <h2 id="titulo-form">Agregar terminal</h2>
            <form id="form-terminal">
                <input type="hidden" id="terminal-id" value="">
                <div class="grid">
                    <div><label for="nombre">Nombre visible</label><input id="nombre" maxlength="80" required placeholder="Ej. Clip mostrador"></div>
                    <div><label for="tiempo">Tiempo de acreditación</label><input id="tiempo" maxlength="100" value="Al día siguiente hábil"></div>
                    <div><label for="comision">Comisión antes de IVA (%)</label><input id="comision" type="number" min="0" max="99" step="0.01" required value="3.50"></div>
                    <div><label for="iva">IVA sobre comisión (%)</label><input id="iva" type="number" min="0" max="99" step="0.01" required value="16.00"></div>
                    <div><label for="fijo">Cargo fijo por operación ($)</label><input id="fijo" type="number" min="0" step="0.01" required value="0.00"></div>
                </div>
                <label class="check"><input id="predeterminada" type="checkbox"> Usar como terminal predeterminada</label>
                <div class="ejemplo">Venta de $100: cobrar <strong id="ejemplo-cobro">$104.24</strong>; el negocio recibe al menos <strong id="ejemplo-neto">$100.00</strong>.</div>
                <div class="actions"><button id="guardar" type="submit">Guardar terminal</button><button id="cancelar" class="secondary" type="button" hidden>Cancelar edición</button></div>
                <div id="mensaje" role="status"></div>
            </form>
        </section>

        <section class="panel" id="panel-lista" <?php echo $esquemaListo ? '' : 'hidden'; ?>>
            <h2>Terminales registradas</h2>
            <div class="table-wrap"><table><thead><tr><th>Terminal</th><th>Comisión</th><th>Acreditación</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="lista-terminales"></tbody></table></div>
        </section>

        <section class="panel" id="panel-impresora">
            <h2>🖨 Impresora térmica en red</h2>
            <p class="intro">La Kinwodon se conecta por cable LAN al mismo switch/router que el servidor. Las tablets imprimirán a través de Nuvi; no requiere Bluetooth, Internet ni una app instalada en cada caja.</p>
            <form id="form-impresora">
                <div class="grid">
                    <div><label for="impresora-nombre">Nombre visible</label><input id="impresora-nombre" maxlength="80" required placeholder="Ej. Térmica mostrador"></div>
                    <div><label for="impresora-host">IP asignada por la red</label><input id="impresora-host" inputmode="decimal" maxlength="45" placeholder="Ej. 192.168.1.210"></div>
                    <div><label for="impresora-puerto">Puerto de impresión</label><input id="impresora-puerto" value="9100" readonly aria-describedby="ayuda-puerto"></div>
                    <div><label class="check" for="impresora-activa"><input id="impresora-activa" type="checkbox"> Activar impresión directa por LAN</label><small id="ayuda-puerto" class="muted">La Kinwodon ESC/POS usa RAW 9100. Reserva esta IP en el router para que no cambie.</small></div>
                </div>
                <div class="ejemplo"><strong>Antes de activar:</strong> imprime la hoja de autoprueba de la térmica, localiza su dirección IPv4 y confirma que está conectada al switch/router de la sucursal, no al puerto WAN.</div>
                <div class="actions"><button id="guardar-impresora" type="submit">Guardar impresora</button><button id="probar-impresora" class="secondary" type="button">Imprimir prueba</button></div>
                <div id="mensaje-impresora" role="status"></div>
            </form>
        </section>
    </main>
    <script>
        let terminales = <?php echo json_encode($terminales, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const $ = id => document.getElementById(id);
        const money = value => '$' + Number(value || 0).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
        function ejemplo() {
            const porcentaje = Number($('comision').value) || 0, iva = Number($('iva').value) || 0, fijo = Number($('fijo').value) || 0;
            const tasa = (porcentaje / 100) * (1 + iva / 100);
            if (tasa >= 1) return;
            const cobro = Math.ceil((((100 + fijo) / (1 - tasa)) * 100) - .000001) / 100;
            const neto = Math.round((cobro - ((cobro * tasa) + fijo)) * 100) / 100;
            $('ejemplo-cobro').textContent = money(cobro); $('ejemplo-neto').textContent = money(neto);
        }
        function mensaje(texto, error = false) { const el = $('mensaje'); el.textContent = texto; el.className = error ? 'error' : 'ok'; }
        function resetear() {
            $('form-terminal').reset(); $('terminal-id').value = ''; $('tiempo').value = 'Al día siguiente hábil'; $('comision').value = '3.50'; $('iva').value = '16.00'; $('fijo').value = '0.00';
            $('titulo-form').textContent = 'Agregar terminal'; $('guardar').textContent = 'Guardar terminal'; $('cancelar').hidden = true; mensaje(''); ejemplo();
        }
        function editar(id) {
            const t = terminales.find(item => Number(item.id) === Number(id)); if (!t) return;
            $('terminal-id').value = t.id; $('nombre').value = t.nombre; $('tiempo').value = t.tiempo_acreditacion; $('comision').value = t.comision_porcentaje; $('iva').value = t.iva_porcentaje; $('fijo').value = t.cargo_fijo; $('predeterminada').checked = Number(t.es_predeterminada) === 1;
            $('titulo-form').textContent = 'Editar ' + t.nombre; $('guardar').textContent = 'Guardar cambios'; $('cancelar').hidden = false; ejemplo(); window.scrollTo({top: $('panel-formulario').offsetTop - 16, behavior:'smooth'});
        }
        async function cambiarEstado(id) {
            const t = terminales.find(item => Number(item.id) === Number(id)); if (!t) return;
            if (!confirm((Number(t.activo) ? '¿Desactivar ' : '¿Reactivar ') + t.nombre + '? Su historial de ventas se conserva.')) return;
            try {
                const r = await fetch('guardar_terminal_pago.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({accion:'desactivar', id})});
                const d = await r.json(); if (!r.ok || !d.success) throw new Error(d.message || 'No se pudo actualizar.'); location.reload();
            } catch (e) { mensaje(e.message, true); }
        }
        function render() {
            $('lista-terminales').innerHTML = '';
            terminales.forEach(t => {
                const tr = document.createElement('tr'); const tasa = (Number(t.comision_porcentaje) * (1 + Number(t.iva_porcentaje) / 100)).toFixed(2);
                tr.innerHTML = `<td><strong></strong><div class="muted"></div></td><td>${Number(t.comision_porcentaje).toFixed(2)}% + IVA<br><span class="muted">Efectiva: ${tasa}%${Number(t.cargo_fijo) ? ' + ' + money(t.cargo_fijo) : ''}</span></td><td></td><td></td><td></td>`;
                tr.children[0].querySelector('strong').textContent = t.nombre; tr.children[0].querySelector('.muted').textContent = 'ID ' + t.id; tr.children[2].textContent = t.tiempo_acreditacion;
                const estado = document.createElement('span'); estado.className = 'pill' + (Number(t.activo) ? '' : ' off'); estado.textContent = Number(t.activo) ? (Number(t.es_predeterminada) ? 'Predeterminada' : 'Activa') : 'Inactiva'; tr.children[3].appendChild(estado);
                const bEditar = document.createElement('button'); bEditar.textContent = 'Editar'; bEditar.className = 'secondary'; bEditar.onclick = () => editar(t.id); tr.children[4].appendChild(bEditar);
                const bEstado = document.createElement('button'); bEstado.textContent = Number(t.activo) ? 'Desactivar' : 'Reactivar'; bEstado.className = Number(t.activo) ? 'danger' : ''; bEstado.style.marginLeft = '6px'; bEstado.onclick = () => cambiarEstado(t.id); tr.children[4].appendChild(bEstado); $('lista-terminales').appendChild(tr);
            });
        }
        $('form-terminal').addEventListener('submit', async event => {
            event.preventDefault(); const porcentaje = Number($('comision').value) || 0, iva = Number($('iva').value) || 0;
            if ((porcentaje / 100) * (1 + iva / 100) >= 1) return mensaje('La comisión total debe ser menor a 100%.', true);
            const boton = $('guardar'); boton.disabled = true;
            try {
                const body = {accion:'guardar', id:$('terminal-id').value, nombre:$('nombre').value.trim(), tiempo_acreditacion:$('tiempo').value.trim(), comision_porcentaje:porcentaje, iva_porcentaje:iva, cargo_fijo:Number($('fijo').value) || 0, es_predeterminada:$('predeterminada').checked};
                const r = await fetch('guardar_terminal_pago.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)}); const d = await r.json(); if (!r.ok || !d.success) throw new Error(d.message || 'No se pudo guardar.'); location.reload();
            } catch (e) { mensaje(e.message, true); } finally { boton.disabled = false; }
        });
        ['comision','iva','fijo'].forEach(id => $(id).addEventListener('input', ejemplo)); $('cancelar').onclick = resetear;
        async function ejecutarActualizacion(boton) {
            boton.disabled = true;
            try {
                const r = await fetch('migrate_comisiones_tarjeta.php');
                const d = await r.json();
                if (!r.ok || !d.success) throw new Error(d.error || 'No se pudo preparar.');
                location.reload();
            } catch (e) {
                alert(e.message);
                boton.disabled = false;
            }
        }
        const migrar = $('migrar'); if (migrar) migrar.onclick = () => ejecutarActualizacion(migrar);

        let configImpresora = <?php echo json_encode($configImpresora, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const mensajeImpresora = (texto, error = false) => {
            const el = $('mensaje-impresora');
            el.textContent = texto;
            el.className = error ? 'error' : 'ok';
        };
        const aplicarConfigImpresora = () => {
            $('impresora-nombre').value = configImpresora.nombre || 'Impresora de tickets';
            $('impresora-host').value = configImpresora.host || '';
            $('impresora-puerto').value = configImpresora.puerto || 9100;
            $('impresora-activa').checked = Boolean(configImpresora.activa);
            $('probar-impresora').disabled = !configImpresora.activa;
        };
        $('form-impresora').addEventListener('submit', async event => {
            event.preventDefault();
            const boton = $('guardar-impresora');
            boton.disabled = true;
            try {
                const respuesta = await fetch('guardar_config_impresora.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        nombre: $('impresora-nombre').value.trim(),
                        host: $('impresora-host').value.trim(),
                        puerto: 9100,
                        activa: $('impresora-activa').checked
                    })
                });
                const datos = await respuesta.json();
                if (!respuesta.ok || !datos.success) throw new Error(datos.message || 'No se pudo guardar la impresora.');
                configImpresora = datos.config;
                aplicarConfigImpresora();
                mensajeImpresora(configImpresora.activa ? 'Impresora guardada. Ya puedes imprimir una prueba.' : 'Impresora guardada como desactivada.');
            } catch (error) {
                mensajeImpresora(error.message, true);
            } finally {
                boton.disabled = false;
            }
        });
        $('probar-impresora').addEventListener('click', async () => {
            if (!confirm('¿Enviar una hoja corta de prueba? La impresora cortará un ticket.')) return;
            const boton = $('probar-impresora');
            boton.disabled = true;
            try {
                const respuesta = await fetch('probar_impresora_ticket.php', {method: 'POST', headers: {'Accept': 'application/json'}});
                const datos = await respuesta.json();
                if (!respuesta.ok || !datos.success) throw new Error(datos.message || 'No se pudo enviar la prueba.');
                mensajeImpresora(datos.message);
            } catch (error) {
                mensajeImpresora(error.message, true);
            } finally {
                boton.disabled = !configImpresora.activa;
            }
        });
        aplicarConfigImpresora();
        render(); ejemplo();
    </script>
</body>
</html>
