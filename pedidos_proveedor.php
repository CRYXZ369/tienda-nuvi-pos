<?php
require_once('auth.php');
tienePermiso(['admin', 'supervisor']);
require_once('conexion.php');
require_once('app_header.php');
require_once('operacion_inventario_utils.php');
require_once('abastecimiento_form_utils.php');
require_once('pedidos_proveedor_utils.php');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function pedidoTexto($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}
function pedidoNumero(float $valor): string
{
    return rtrim(rtrim(number_format($valor, 3, '.', ''), '0'), '.');
}
function pedidoDias(string $valor): array
{
    return $valor === '' ? [] : nuviDiasProveedor(explode(',', $valor));
}

$inventarioListo = nuviOperacionInventarioListo($conexion);
$lista = $inventarioListo && nuviOperacionTablaExiste($conexion, 'proveedor_agenda')
    && nuviOperacionTablaExiste($conexion, 'proveedor_productos');
$mensajes = [
    'preparado' => 'Agenda preparada. No se modificaron productos ni existencias.',
    'guardado' => 'Cambios guardados.',
    'formulario_vencido' => 'El formulario ya se envió o venció. Revisa los cambios antes de volver a intentarlo.',
    'invalido' => 'Revisa los datos, el producto y la presentación. Para piezas, la presentación debe ser un número entero.',
    'error' => 'No se pudieron guardar los cambios. Revisa los datos e inténtalo de nuevo.',
];
$estado = (string) ($_GET['estado'] ?? '');
$id = max(0, (int) ($_GET['proveedor'] ?? 0));
$proveedores = $productos = $articulos = [];
$seleccionado = null;
$cobertura = null;
$diasSemana = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
if ($lista) {
    try {
        $ahora = mysqli_fetch_assoc(mysqli_query($conexion, 'SELECT CURDATE() AS fecha'));
        $hoy = new DateTimeImmutable($ahora['fecha']);
        $res = mysqli_query($conexion, "SELECT p.*, COALESCE(a.dias_preventa,'') AS dias_preventa, COALESCE(a.dias_entrega,'') AS dias_entrega
            FROM proveedores p LEFT JOIN proveedor_agenda a ON a.id_proveedor=p.id WHERE p.activo=1 ORDER BY p.nombre");
        while ($fila = mysqli_fetch_assoc($res)) {
            $proveedores[] = $fila;
            if ((int) $fila['id'] === $id) $seleccionado = $fila;
        }
        if ($seleccionado) {
            $preventa = pedidoDias($seleccionado['dias_preventa']);
            $entrega = pedidoDias($seleccionado['dias_entrega']);
            $cobertura = nuviCoberturaPedido($hoy, $preventa, $entrega);
            $res = mysqli_query($conexion, "SELECT id, nombre, codigo_barras FROM productos WHERE activo IS NULL OR activo=1 ORDER BY nombre");
            while ($fila = mysqli_fetch_assoc($res)) $productos[] = $fila;
            $sql = "SELECT p.id, p.nombre, p.stock, p.unidad_medida, p.fecha_registro, pp.unidades_paquete,
                COALESCE(h.ventas,0) AS ventas, COALESCE(h.dias_con_ventas,0) AS dias_con_ventas
                FROM proveedor_productos pp JOIN productos p ON p.id=pp.id_producto
                LEFT JOIN (SELECT d.id_producto, SUM(d.cantidad) AS ventas, COUNT(DISTINCT DATE(v.fecha_venta)) AS dias_con_ventas
                    FROM ventas v JOIN detalle_ventas d ON d.id_venta=v.id_venta
                    JOIN proveedor_productos vinculados ON vinculados.id_producto=d.id_producto AND vinculados.id_proveedor=?
                    WHERE v.fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) AND v.fecha_venta < CURDATE()
                    AND COALESCE(v.metodo_pago,'') <> 'devuelto' GROUP BY d.id_producto) h ON h.id_producto=p.id
                WHERE pp.id_proveedor=? AND (p.activo IS NULL OR p.activo=1)
                ORDER BY p.nombre";
            $stmt = mysqli_prepare($conexion, $sql);
            mysqli_stmt_bind_param($stmt, 'ii', $id, $id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($fila = mysqli_fetch_assoc($res)) {
                $alta = new DateTimeImmutable(substr($fila['fecha_registro'], 0, 10));
                $fila['dias_historial'] = $alta < $hoy ? min(28, (int) $alta->diff($hoy)->days) : 0;
                $fila['sugerencia'] = $cobertura ? nuviSugerenciaPedido((float) $fila['ventas'], $fila['dias_historial'], (float) $fila['stock'],
                    $cobertura['dias'], $fila['unidades_paquete'] === null ? null : (float) $fila['unidades_paquete'], (string) $fila['unidad_medida']) : null;
                $articulos[] = $fila;
            }
            usort($articulos, static function (array $a, array $b): int {
                if (($a['sugerencia'] === null) !== ($b['sugerencia'] === null)) return $a['sugerencia'] === null ? 1 : -1;
                $necesidad = ($b['sugerencia'] ?? 0) <=> ($a['sugerencia'] ?? 0);
                if ($necesidad !== 0) return $necesidad;
                $promedioA = $a['dias_historial'] > 0 ? (float) $a['ventas'] / $a['dias_historial'] : 0;
                $promedioB = $b['dias_historial'] > 0 ? (float) $b['ventas'] / $b['dias_historial'] : 0;
                return ($promedioB <=> $promedioA) ?: strcasecmp($a['nombre'], $b['nombre']);
            });
        }
    } catch (Throwable $e) {
        error_log('pedidos_proveedor: ' . $e->getMessage());
        http_response_code(500);
        exit('No se pudieron cargar las sugerencias. Consulta el registro de errores e inténtalo de nuevo.');
    }
}
$token = $lista && $seleccionado ? nuviCrearTokenAbastecimiento('pedido_proveedor') : '';
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pedidos a proveedores · Nuvi</title>
<?php renderAppHeaderStyles(); ?>
<style>
body{margin:0;padding:10px;font-family:system-ui,sans-serif;background:#f4f6f8;color:#1e293b}
.contenedor{max-width:1200px;margin:auto;padding:24px;background:#fff;border-radius:16px}
.panel{border:1px solid #cbd5e1;border-radius:12px;padding:18px;margin:16px 0;background:#f8fafc}
label{display:block;font-weight:650;margin:8px 0} input,select,textarea{font:inherit;padding:9px;border:1px solid #94a3b8;border-radius:7px;box-sizing:border-box}
input[type=checkbox]{width:auto} input[type=number]{width:120px;max-width:100%} textarea{width:100%;min-height:60px}
button,.boton{display:inline-block;background:#1d4ed8;color:#fff;border:0;border-radius:8px;padding:10px 14px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
button.secundario{background:#334155;color:#fff}.fila{display:flex;flex-wrap:wrap;gap:12px;align-items:end}
.semana label{display:inline-block;margin:5px 12px 5px 0}.scroll{overflow:auto}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:10px;border-bottom:1px solid #cbd5e1}
.aviso{border-left:4px solid #b45309;padding:10px;background:#fffbeb;color:#78350f}
.estado{padding:10px;border:1px solid #64748b;border-radius:8px}
html.nuvi-tema-oscuro .aviso{background:#422006!important;color:#fef3c7!important;border-color:#fbbf24!important}
html.nuvi-tema-oscuro .panel{background:#172033!important;color:#e2e8f0!important}
@media print{.no-imprimir,.app-header,form.configuracion{display:none!important}.contenedor{box-shadow:none}}
</style></head><body>
<?php renderAppHeader('abastecimiento'); ?>
<main class="contenedor">
<h1>📋 Pedidos a proveedores</h1>
<p>Consulta la próxima visita y prepara una lista orientativa. Nuvi no envía pedidos ni modifica existencias.</p>
<p><a href="abastecimiento.php">← Volver a entradas y mermas</a></p>
<?php if (isset($mensajes[$estado])): ?><p class="estado" role="status"><?php echo pedidoTexto($mensajes[$estado]); ?></p><?php endif; ?>
<?php if (!$lista): ?>
    <section class="panel"><h2>Preparar agenda</h2>
    <?php if (!$inventarioListo): ?><p>Primero prepara la estructura en Entradas y mermas.</p>
    <?php else: ?><p>Un administrador debe preparar las tablas de agenda y productos por proveedor. No modifica inventario ni ventas.</p>
    <?php if (($_SESSION['rol'] ?? '') === 'admin'): ?><form method="post" action="migrate_pedidos_proveedor.php">
        <input type="hidden" name="token_operacion" value="<?php echo pedidoTexto(nuviCrearTokenAbastecimiento('migrar_pedidos')); ?>">
        <button>Preparar agenda</button></form><?php endif; ?><?php endif; ?></section>
<?php else: ?>
    <section class="panel"><h2>Proveedores</h2>
    <?php if (!$proveedores): ?><p>Primero registra un proveedor en Entradas y mermas.</p>
    <?php else: ?><p>Preventa de hoy:
        <?php $visitas = []; foreach ($proveedores as $p) if (in_array((int) $hoy->format('N'), pedidoDias($p['dias_preventa']), true)) $visitas[] = $p['nombre']; echo pedidoTexto($visitas ? implode(', ', $visitas) : 'ninguna registrada'); ?>.</p>
    <form method="get" class="fila"><label>Selecciona proveedor
        <select name="proveedor" required><option value="">Elige uno…</option><?php foreach ($proveedores as $p): ?><option value="<?php echo (int) $p['id']; ?>" <?php echo (int) $p['id'] === $id ? 'selected' : ''; ?>><?php echo pedidoTexto($p['nombre']); ?></option><?php endforeach; ?></select></label><button>Ver proveedor</button></form>
    <?php endif; ?></section>
    <?php if ($id && !$seleccionado): ?><p class="estado">Proveedor no encontrado o inactivo.</p><?php endif; ?>
    <?php if ($seleccionado): ?>
    <section class="panel"><h2><?php echo pedidoTexto($seleccionado['nombre']); ?> · lista para pedir</h2>
    <?php if ($cobertura): ?>
        <p>Próxima preventa: <strong><?php echo pedidoTexto($cobertura['pedido']->format('d/m/Y')); ?></strong> · entrega estimada: <strong><?php echo pedidoTexto($cobertura['entrega']->format('d/m/Y')); ?></strong> · siguiente entrega estimada: <strong><?php echo pedidoTexto($cobertura['siguiente_entrega']->format('d/m/Y')); ?></strong>.</p>
        <p>Se estima desde hoy hasta la siguiente entrega, incluyendo hoy y ese día: <?php echo $cobertura['dias']; ?> días. Son fechas estimadas, sin feriados ni confirmación del proveedor.</p>
    <?php else: ?><p class="aviso">Configura al menos un día de preventa y uno de entrega para calcular sugerencias.</p><?php endif; ?>
    <p class="aviso">Revisa la mercancía y las ventas pendientes de sincronizar. No se consideran pedidos ya realizados ni entregas pendientes; las ventas offline aparecen hasta sincronizarse y se cuentan por fecha de recepción. No se descuentan días sin stock ni se predicen promociones.</p>
    <p>Ventas de los últimos 28 días completos, sin contar hoy ni ventas devueltas. Fórmula: promedio diario × días de cobertura − stock actual; mínimo cero y redondeo por presentación. Se muestran primero las cantidades sugeridas más grandes; las unidades diferentes no son directamente comparables. Si no hay ventas registradas, se indica «Revisar» en vez de adivinar. Los ajustes de cantidad son temporales: imprime la lista antes de salir.</p>
    <?php if (!$articulos): ?><p>No hay productos asociados. Agrégalos abajo o importa el historial de compras comprobadas.</p>
    <?php else: ?><div class="scroll"><table><thead><tr><th>Producto</th><th>Stock</th><th>Ventas / historial</th><th>Presentación</th><th>Sugerencia editable</th><th class="no-imprimir">Administrar</th></tr></thead><tbody>
    <?php foreach ($articulos as $a): $unidad = $a['unidad_medida'] ?: 'pza'; ?>
        <tr><td><?php echo pedidoTexto($a['nombre']); ?></td><td><?php echo pedidoNumero((float) $a['stock']) . ' ' . pedidoTexto($unidad); ?></td>
        <td><?php echo pedidoNumero((float) $a['ventas']); ?> en <?php echo (int) $a['dias_historial']; ?> días<?php if ($a['dias_historial'] < 28): ?> · historial corto<?php endif; ?><br><small><?php echo (int) $a['dias_con_ventas']; ?> días con ventas</small></td>
        <td><?php echo $a['unidades_paquete'] === null ? 'Sin definir' : pedidoNumero((float) $a['unidades_paquete']) . ' ' . pedidoTexto($unidad); ?></td>
        <td><?php if ($a['sugerencia'] === null): ?><strong>Revisar</strong><?php endif; ?><label><span>Unidades</span> <input type="number" min="0" step="<?php echo strtolower(trim($unidad)) === 'pza' ? '1' : '0.001'; ?>" value="<?php echo $a['sugerencia'] === null ? '' : pedidoNumero($a['sugerencia']); ?>" aria-label="Cantidad a pedir de <?php echo pedidoTexto($a['nombre']); ?>"></label><?php if ($a['sugerencia'] !== null && $a['unidades_paquete'] !== null): ?><small>Sugerido: <?php echo pedidoNumero($a['sugerencia'] / (float) $a['unidades_paquete']); ?> paquetes</small><?php endif; ?></td>
        <td class="no-imprimir"><details><summary>Presentación o quitar</summary>
            <form method="post" action="guardar_pedido_proveedor.php"><input type="hidden" name="token_operacion" value="<?php echo pedidoTexto($token); ?>"><input type="hidden" name="proveedor" value="<?php echo $id; ?>"><input type="hidden" name="producto" value="<?php echo (int) $a['id']; ?>"><input type="hidden" name="accion" value="producto">
            <label>Unidades por paquete (vacío = sin definir)<input name="paquete" type="number" min="0.001" step="0.001" value="<?php echo pedidoTexto($a['unidades_paquete']); ?>"></label><button>Guardar</button></form>
            <form method="post" action="guardar_pedido_proveedor.php" onsubmit="return confirm('¿Quitar este producto del proveedor?');"><input type="hidden" name="token_operacion" value="<?php echo pedidoTexto($token); ?>"><input type="hidden" name="proveedor" value="<?php echo $id; ?>"><input type="hidden" name="producto" value="<?php echo (int) $a['id']; ?>"><input type="hidden" name="accion" value="quitar"><button class="secundario">Quitar</button></form>
        </details></td></tr>
    <?php endforeach; ?></tbody></table></div><p class="no-imprimir"><button type="button" onclick="window.print()">Imprimir lista</button></p><?php endif; ?></section>

    <section class="panel no-imprimir"><h2>Productos que surte</h2>
    <form method="post" action="guardar_pedido_proveedor.php" class="configuracion">
        <input type="hidden" name="token_operacion" value="<?php echo pedidoTexto($token); ?>"><input type="hidden" name="proveedor" value="<?php echo $id; ?>"><input type="hidden" name="accion" value="agregar">
        <div class="fila"><label>Código para buscar <input id="codigo-producto" type="text" autocomplete="off"></label>
        <label>Producto <select id="producto" name="producto" required><option value="">Selecciona…</option><?php foreach ($productos as $p): ?><option value="<?php echo (int) $p['id']; ?>" data-codigo="<?php echo pedidoTexto($p['codigo_barras']); ?>"><?php echo pedidoTexto($p['nombre']); ?></option><?php endforeach; ?></select></label>
        <label>Unidades por paquete (opcional) <input name="paquete" type="number" min="0.001" step="0.001"></label><button>Asociar producto</button></div><p id="aviso-codigo" role="status"></p>
    </form>
    <form method="post" action="guardar_pedido_proveedor.php" class="configuracion"><input type="hidden" name="token_operacion" value="<?php echo pedidoTexto($token); ?>"><input type="hidden" name="proveedor" value="<?php echo $id; ?>"><input type="hidden" name="accion" value="historial"><button class="secundario">Añadir productos de compras registradas</button></form>
    <p>Solo añade productos presentes en compras de este proveedor; no supone que una marca sea un proveedor. Puedes quitar relaciones equivocadas.</p></section>

    <section class="panel no-imprimir"><h2>Datos y días de visita</h2>
    <form method="post" action="guardar_pedido_proveedor.php" class="configuracion"><input type="hidden" name="token_operacion" value="<?php echo pedidoTexto($token); ?>"><input type="hidden" name="proveedor" value="<?php echo $id; ?>"><input type="hidden" name="accion" value="agenda">
    <div class="fila"><?php foreach (['nombre'=>'Nombre','contacto'=>'Contacto','telefono'=>'Teléfono','email'=>'Correo'] as $campo => $etiqueta): ?><label><?php echo $etiqueta; ?> <input name="<?php echo $campo; ?>" value="<?php echo pedidoTexto($seleccionado[$campo]); ?>" <?php echo $campo === 'nombre' ? 'required maxlength="120"' : ''; ?>></label><?php endforeach; ?></div>
    <label>Notas <textarea name="notas" maxlength="500"><?php echo pedidoTexto($seleccionado['notas']); ?></textarea></label>
    <fieldset class="semana"><legend>Preventa / toma de pedido</legend><?php foreach ($diasSemana as $num => $dia): ?><label><input type="checkbox" name="preventa[]" value="<?php echo $num; ?>" <?php echo in_array($num, $preventa, true) ? 'checked' : ''; ?>> <?php echo $dia; ?></label><?php endforeach; ?></fieldset>
    <fieldset class="semana"><legend>Entrega</legend><?php foreach ($diasSemana as $num => $dia): ?><label><input type="checkbox" name="entrega[]" value="<?php echo $num; ?>" <?php echo in_array($num, $entrega, true) ? 'checked' : ''; ?>> <?php echo $dia; ?></label><?php endforeach; ?></fieldset><p><button>Guardar datos y días</button></p></form></section>
    <?php endif; ?>
<?php endif; ?>
</main>
<script>
document.getElementById('codigo-producto')?.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const codigo = event.currentTarget.value.trim();
    const selector = document.getElementById('producto');
    const opcion = Array.from(selector.options).find(item => item.value && item.dataset.codigo === codigo);
    document.getElementById('aviso-codigo').textContent = opcion ? `Seleccionado: ${opcion.textContent}` : 'Código no encontrado. Busca el producto en la lista.';
    selector.value = opcion ? opcion.value : '';
});
</script></body></html>
