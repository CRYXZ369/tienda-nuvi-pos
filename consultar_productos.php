<?php
// Incluimos la protección de sesión y permisos
include("auth.php");
tienePermiso(['admin', 'supervisor']);
include("conexion.php");
require_once("app_header.php");

// Consultamos los productos
/*$sql = "SELECT * FROM productos ORDER BY id DESC";
$resultado = mysqli_query($conexion, $sql);
*/
$resultado = [];
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Inventario Inteligente - Nuvi</title>
    <style>
        /* ESTILO PROFESIONAL Y LIMPIO */
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        body {
            margin: 0;
            padding: 20px;
            background-color: #f4f6f8;
            color: #334155;
        }

        .contenedor {
            max-width: 1200px;
            margin: auto;
            background: #ffffff;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }

        h2 {
            color: #1e293b;
            text-align: center;
            margin-bottom: 25px;
        }

        .busqueda-container {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .input-busqueda {
            width: 100%;
            padding: 14px 14px 14px 40px;
            font-size: 1.1rem;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            background-color: #f8fafc;
            outline: none;
            margin-bottom: 0;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 0 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>');
            background-repeat: no-repeat;
            background-position: 12px center;
            background-size: 16px;
            transition: border-color 0.2s;
        }

        .input-busqueda:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
        }

        .input-busqueda:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
        }

        /* TABLA */
        .contenedor-tabla {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        th {
            background-color: #f8fafc;
            color: #64748b;
            padding: 14px;
            text-align: left;
            text-transform: uppercase;
            font-size: 0.85rem;
            cursor: pointer;
            border-bottom: 2px solid #e2e8f0;
        }

        th.asc::after {
            content: " 🔼";
            font-size: 0.7rem;
        }

        th.desc::after {
            content: " 🔽";
            font-size: 0.7rem;
        }

        td {
            padding: 14px;
            border-bottom: 1px solid #e2e8f0;
        }

        /* Celdas editables */
        .editable {
            background-color: #fffdf2;
            cursor: cell;
        }

        .editable:focus {
            background-color: #ffffff;
            outline: 2px solid #2563eb;
        }

        .col-nombre-unidad::after {
            content: ' · ' attr(data-unidad);
            display: inline-block;
            margin-left: 4px;
            color: #64748b;
            font-size: .76rem;
            font-weight: 700;
        }

        /* RESPONSIVO */
        @media (max-width: 600px) {
            header {
                flex-direction: column;
            }

            .contenedor {
                padding: 15px;
            }

            .busqueda-container {
                flex-direction: column;
                align-items: stretch;
            }
        }

        /* En caja se usa principalmente tablet: el ID y el código sirven para
           localizar y editar rápido, por lo que siempre quedan disponibles.
           Marca/proveedor también se conserva visible; categoría puede verse
           con el desplazamiento horizontal cuando haga falta. */
        @media (max-width: 1024px) {
            #tablaInventario { min-width: 900px; }
            #tablaInventario th:nth-child(8), #tablaInventario .col-categoria {
                display: none;
            }

            #tablaInventario th, #tablaInventario td { padding: 10px 8px; }
            #tablaInventario .col-id { min-width: 50px; }
            #tablaInventario .col-codigo { min-width: 128px; white-space: nowrap; }
            #tablaInventario .td-acciones { min-width: 210px; }
            #tablaInventario .margen-tools { margin-top: 6px !important; padding: 6px !important; }
            #tablaInventario .margen-buttons { display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px !important; }
            #tablaInventario .margen-buttons button { width: 100%; padding: 5px 6px !important; font-size: .72rem !important; }
        }

        @media (max-width: 640px) {
            .contenedor { padding: 12px; }
            #tablaInventario { min-width: 850px; font-size: .87rem; }
            #tablaInventario .td-acciones { min-width: 180px; }
            #tablaInventario .margen-sugerido-label { font-size: .7rem !important; }
        }

        .modal-codigo-box {
            width: min(92vw, 480px);
            background: #ffffff;
            color: #1e293b;
            border-radius: 14px;
            box-shadow: 0 20px 55px rgba(15, 23, 42, .35);
            overflow: hidden;
        }
        .modal-codigo-box header { padding: 16px 18px; background: #1d4ed8; color: #ffffff; font-weight: 800; }
        .modal-codigo-box .modal-codigo-body { padding: 18px; }
        .modal-codigo-box input { width: 100%; padding: 12px; border: 2px solid #93c5fd; border-radius: 9px; font: 1.05rem ui-monospace, SFMono-Regular, Menlo, monospace; }
        .modal-codigo-box .modal-codigo-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
        html.nuvi-tema-oscuro .modal-codigo-box { background: #172033; color: #e2e8f0; }
        html.nuvi-tema-oscuro .modal-codigo-box input { background: #0f172a; color: #f8fafc; border-color: #475569; }
    </style>
    <?php renderAppHeaderStyles(); ?>
</head>

<body>
    <?php renderAppHeader('inventario'); ?>

    <div class="contenedor">
        <h2>📋 Inventario de Productos</h2>

        <div class="busqueda-container">
            <input type="text" id="buscador" class="input-busqueda" placeholder="🔍 Busca por nombre, marca, categoría, código o ID (palabras en cualquier orden)..." autocomplete="off" spellcheck="false">
            <button id="btnRefrescarCache" class="btn-refrescar" title="Limpiar cache local y refrescar búsqueda">Refrescar cache</button>
            <button id="btnVerCriticos" class="btn-criticos" type="button">🔥 Productos Críticos</button>
            <button id="btnStockNegativo" class="btn-criticos" type="button" aria-pressed="false">Ver existencias negativas</button>
            <a class="btn-abastecimiento" href="abastecimiento.php">📥 Entradas y mermas</a>
        </div>
        <p id="avisoStockNegativo" role="status" aria-live="polite" style="display:none; padding:12px; background:#fff7ed; color:#9a3412; border:1px solid #fdba74; border-radius:8px; font-weight:700;"></p>
        <div id="mensajeInventario" role="status" style="display:none; margin:-8px 0 16px; padding:10px 12px; border-radius:8px;"></div>

        <div class="contenedor-tabla">
            <table id="tablaInventario">
                <thead>
                    <tr>
                        <th data-field="id">ID</th>
                        <th data-field="codigo_barras">Código</th>
                        <th data-field="nombre">Nombre</th>
                        <th data-field="marca" title="Puedes registrar aquí la marca, empresa o proveedor habitual">Marca / proveedor</th>
                        <th data-field="precio_venta">Venta</th>
                        <th data-field="precio_compra">Compra</th>
                        <th data-field="stock">Stock</th>
                        <th data-field="categoria">Categoría</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="cuerpoTabla">

                </tbody>
            </table>
        </div>
    </div>

    <div id="modalCriticos" class="modal-backdrop" onclick="if(event.target.id==='modalCriticos'){cerrarModalCriticos();}">
        <div class="modal-box">
            <div class="modal-header">
                <strong>Productos críticos: demanda y cobertura</strong>
                <button type="button" onclick="cerrarModalCriticos()" style="border:none;background:rgba(255,255,255,.22);color:#fff;padding:6px 10px;border-radius:8px;cursor:pointer;">Cerrar</button>
            </div>
            <div class="modal-body" id="modalCriticosBody">Cargando...</div>
        </div>
    </div>

    <div id="modalEditarCodigo" class="modal-backdrop" style="display:none; align-items:center; justify-content:center;" onclick="if(event.target.id==='modalEditarCodigo'){cerrarEditorCodigoBarras();}">
        <div class="modal-codigo-box" role="dialog" aria-modal="true" aria-labelledby="tituloEditarCodigo">
            <header id="tituloEditarCodigo">🏷️ Editar código de barras</header>
            <div class="modal-codigo-body">
                <p id="descripcionEditarCodigo" style="margin-top:0; color:inherit;"></p>
                <label for="inputEditarCodigo" style="font-weight:700; display:block; margin-bottom:7px;">Código nuevo</label>
                <input id="inputEditarCodigo" type="text" maxlength="80" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Escanea o escribe el código">
                <small style="display:block; margin-top:8px; opacity:.8;">Al escanear, el lector puede guardar con su tecla Enter. No se crea otro producto.</small>
                <div class="modal-codigo-actions">
                    <button type="button" class="btn-toggle" onclick="cerrarEditorCodigoBarras()">Cancelar</button>
                    <button id="btnGuardarCodigo" type="button" style="background:#2563eb; color:#fff; border:none; padding:8px 12px; border-radius:7px; font-weight:700; cursor:pointer;">Guardar código</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // CONSTANTES
        window.__nuviBusquedaApiActiva = true;
        const LONGITUDES_CODIGO_BARRAS = [8, 12, 13, 14];

        // FUNCIÓN BUSCADOR INTELIGENTE
        function busquedaInteligente(termino, productos) {
            const palabrasBuscadas = termino.toLowerCase().split(" ").filter(p => p.trim() !== "");

            return productos.filter(p => {
                const textoCompleto = `${p.nombre || ''} ${p.marca || ''} ${p.categoria || ''} ${p.codigo_barras || ''}`.toLowerCase();
                return palabrasBuscadas.every(palabra => textoCompleto.includes(palabra));
            });
        }

        // FILTRADO DINÁMICO CON BUSCADOR INTELIGENTE
        let productosInventario = [];
        window.productosInventario = productosInventario;

        // Precargar productos desde la tabla
        document.addEventListener('DOMContentLoaded', function() {
                const filas = document.querySelectorAll('#tablaInventario tbody tr');
            filas.forEach(fila => {
                const celdas = fila.querySelectorAll('td');
                productosInventario.push({
                    id: celdas[0].innerText,
                    codigo_barras: celdas[1].innerText,
                    nombre: celdas[2].innerText,
                    marca: celdas[3].innerText,
                    precio_venta: celdas[4].innerText,
                    precio_compra: celdas[5].innerText,
                    stock: celdas[6].innerText.replace(' [AGOTADO]', '').replace(' [SURTIR]', ''),
                    categoria: celdas[7].innerText
                });
            });
            window.productosInventario = productosInventario;
        });

        document.getElementById('buscador').addEventListener('input', function() {
            if (window.__nuviBusquedaApiActiva) return;
            const termino = this.value.trim();
            const filas = document.querySelectorAll('#tablaInventario tbody tr');

            if (!termino) {
                filas.forEach(fila => fila.style.display = '');
                return;
            }

            const coincidencias = busquedaInteligente(termino, productosInventario);
            const idsCoincidentes = coincidencias.map(p => p.id);

            filas.forEach(fila => {
                const idFila = fila.querySelector('td').innerText;
                fila.style.display = idsCoincidentes.includes(idFila) ? '' : 'none';
            });
        });

        // EDICIÓN EN TIEMPO REAL (AJAX)
        // EDICIÓN EN TIEMPO REAL (AJAX SIN RECARGAR)
        function guardarCambio(elemento, columna) {
            // 1. Limpiamos las etiquetas para quedarnos solo con el número/texto real
            let nuevoValor = elemento.innerText.replace(' [AGOTADO]', '').replace(' [SURTIR]', '').trim();
            let idProducto = elemento.parentElement.getAttribute('data-id');

            let datos = new FormData();
            datos.append('id', idProducto);
            datos.append('columna', columna);
            datos.append('valor', nuevoValor);

            fetch('actualizar_inventario.php', {
                    method: 'POST',
                    body: datos
                })
                .then(async res => {
                    const text = (await res.text()).trim();
                    let ok = false;

                    // Intentar parsear JSON si viene así
                    try {
                        const json = JSON.parse(text);
                        // Aceptar varias claves por compatibilidad ('status', 'staus')
                        if (json.status === 'ok' || json.staus === 'ok') ok = true;
                    } catch (e) {
                        // No es JSON, tratar como respuesta plana
                        if (text === 'ok') ok = true;
                    }

                    if (ok) {
                        // Parpadeo verde para confirmar que se guardó
                        elemento.style.backgroundColor = "#dcfce7";
                        setTimeout(() => elemento.style.backgroundColor = "", 1000);

                        // --- LÓGICA DEL SEMÁFORO EN TIEMPO REAL ---
                        if (columna === 'stock') {
                            let stockNum = parseFloat(nuevoValor);
                            let colorStock = "#10b981"; // Verde por defecto
                            let aviso = "";

                            if (stockNum <= 0) {
                                colorStock = "#ef4444"; // Rojo
                                aviso = " [AGOTADO]";
                            } else if (stockNum <= 5) {
                                colorStock = "#f59e0b"; // Naranja
                                aviso = " [SURTIR]";
                            }

                            // Aplicamos los colores y el texto sin recargar la página
                            elemento.style.color = colorStock;
                            elemento.innerText = nuevoValor + aviso;
                        }
                    } else {
                        alert("Error al guardar: " + text);
                    }
                })
                .catch(error => {
                    alert("Error de conexión al guardar.");
                    console.error(error);
                });
        }

        // ORDENAMIENTO (función original)
        /*function ordenarTabla(n) {
            let table = document.getElementById("tabla-inventario");
            let switching = true, i, x, y, shouldSwitch, dir = "asc", switchcount = 0;
            while (switching) {
                switching = false;
                let rows = table.rows;
                for (i = 1; i < (rows.length - 1); i++) {
                    shouldSwitch = false;
                    x = rows[i].getElementsByTagName("TD")[n];
                    y = rows[i + 1].getElementsByTagName("TD")[n];
                    let valX = isNaN(x.innerHTML) ? x.innerHTML.toLowerCase() : parseFloat(x.innerHTML);
                    let valY = isNaN(y.innerHTML) ? y.innerHTML.toLowerCase() : parseFloat(y.innerHTML);
                    if (dir == "asc" ? valX > valY : valX < valY) { shouldSwitch = true; break; }
                }
                if (shouldSwitch) { rows[i].parentNode.insertBefore(rows[i + 1], rows[i]); switching = true; switchcount++; }
                else if (switchcount == 0 && dir == "asc") { dir = "desc"; switching = true; }
            }
        }*/



        function ordenarTabla(n) {
            const table = document.getElementById("tabla-inventario");
            const tbody = table.querySelector("tbody");
            const filas = Array.from(tbody.rows); // Convertimos las filas en un Array para usar .sort()

            // Determinamos la dirección (ascendente/descendente)
            const th = table.querySelectorAll("th")[n];
            const esAsc = !th.classList.contains("asc");

            // Limpiamos indicadores de otras columnas
            table.querySelectorAll("th").forEach(header => header.classList.remove("asc", "desc"));

            // El corazón de la mejora: Ordenamiento en memoria
            const filasOrdenadas = filas.sort((a, b) => {
                let valA = a.cells[n].innerText.replace(' [AGOTADO]', '').replace(' [SURTIR]', '').trim();
                let valB = b.cells[n].innerText.replace(' [AGOTADO]', '').replace(' [SURTIR]', '').trim();

                // Convertir a número si es posible (para ID, Precios y Stock)
                let numA = isNaN(valA) || valA === "" ? valA.toLowerCase() : parseFloat(valA);
                let numB = isNaN(valB) || valB === "" ? valB.toLowerCase() : parseFloat(valB);

                if (numA < numB) return esAsc ? -1 : 1;
                if (numA > numB) return esAsc ? 1 : -1;
                return 0;
            });

            // Reinsertamos las filas ya ordenadas (esto es mucho más rápido para el navegador)
            th.classList.toggle("asc", esAsc);
            th.classList.toggle("desc", !esAsc);

            // Usamos un DocumentFragment para que el navegador solo renderice UNA VEZ
            const fragment = document.createDocumentFragment();
            filasOrdenadas.forEach(fila => fragment.appendChild(fila));
            tbody.appendChild(fragment);
        }

    </script>

    <script>
        // Mostrar productos inactivos en la vista de inventario (admin)
        window.BUSCAR_INCLUDE_INACTIVE = 1;
        const CRITICOS_CONFIG_KEY = 'nuvi_criticos_surtido_v1_<?php echo (int) ($_SESSION['id_usuario'] ?? 0); ?>';
        let productosCriticosActuales = [];
        let configuracionSurtidoCriticos = leerConfiguracionSurtidoCriticos();

        function leerConfiguracionSurtidoCriticos() {
            try {
                const guardada = JSON.parse(localStorage.getItem(CRITICOS_CONFIG_KEY) || '{}');
                const diasEntrega = Number(guardada.diasEntrega);
                const diasSeguridad = Number(guardada.diasSeguridad);
                return {
                    diasEntrega: [3, 7, 14].includes(diasEntrega) ? diasEntrega : 7,
                    diasSeguridad: [2, 3, 7].includes(diasSeguridad) ? diasSeguridad : 3
                };
            } catch (error) {
                return { diasEntrega: 7, diasSeguridad: 3 };
            }
        }

        function guardarConfiguracionSurtidoCriticos() {
            try { localStorage.setItem(CRITICOS_CONFIG_KEY, JSON.stringify(configuracionSurtidoCriticos)); } catch (error) {}
        }

        function esUnidadFraccionable(unidad) {
            return /\b(kg|kilo|g|gr|gram|l|lt|litro|ml)\b/i.test(String(unidad || ''));
        }

        function calcularSurtidoRecomendado(producto) {
            const ventaDiaria = Number(producto.venta_diaria || 0);
            const stock = Number(producto.stock || 0);
            const diasObjetivo = configuracionSurtidoCriticos.diasEntrega + configuracionSurtidoCriticos.diasSeguridad;
            if (!Number.isFinite(ventaDiaria) || ventaDiaria <= 0) {
                return { cantidad: null, diasObjetivo };
            }

            const faltante = Math.max(0, (ventaDiaria * diasObjetivo) - stock);
            const cantidad = esUnidadFraccionable(producto.unidad_medida)
                ? Math.ceil(faltante * 1000) / 1000
                : Math.ceil(faltante);
            return { cantidad, diasObjetivo };
        }

        function formatearCantidadSurtido(cantidad) {
            if (!Number.isFinite(cantidad)) return '—';
            return Number.isInteger(cantidad) ? String(cantidad) : cantidad.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
        }

        function escaparTextoSurtido(valor) {
            return String(valor ?? '').replace(/[&<>'"]/g, caracter => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
            })[caracter]);
        }

        function obtenerLineasSurtido() {
            return productosCriticosActuales
                .map(producto => ({ producto, surtido: calcularSurtidoRecomendado(producto) }))
                .filter(({ surtido }) => Number.isFinite(surtido.cantidad) && surtido.cantidad > 0)
                .map(({ producto, surtido }) => ({
                    nombre: String(producto.nombre || 'Sin nombre'),
                    marca: String(producto.marca || ''),
                    codigo: String(producto.codigo_barras || ''),
                    unidad: String(producto.unidad_medida || 'unid.'),
                    cantidad: surtido.cantidad
                }));
        }

        function textoListaSurtido() {
            const lineas = obtenerLineasSurtido();
            const diasObjetivo = configuracionSurtidoCriticos.diasEntrega + configuracionSurtidoCriticos.diasSeguridad;
            const encabezado = [
                'LISTA DE SURTIDO · Miscelánea Nuvi',
                `Cobertura objetivo: ${diasObjetivo} días (${configuracionSurtidoCriticos.diasEntrega} de entrega + ${configuracionSurtidoCriticos.diasSeguridad} de colchón)`,
                `Generada: ${new Date().toLocaleString('es-MX')}`,
                ''
            ];
            const detalle = lineas.map((linea, indice) => {
                const referencia = [linea.marca, linea.codigo ? `cód. ${linea.codigo}` : ''].filter(Boolean).join(' · ');
                return `${indice + 1}. ${linea.nombre} — pedir ${formatearCantidadSurtido(linea.cantidad)} ${linea.unidad}${referencia ? ` (${referencia})` : ''}`;
            });
            return [...encabezado, ...(detalle.length ? detalle : ['No hay cantidades sugeridas para surtir.'])].join('\n');
        }

        async function copiarListaSurtido() {
            const lineas = obtenerLineasSurtido();
            if (!lineas.length) {
                alert('No hay cantidades sugeridas para copiar. Revisa los productos críticos manualmente.');
                return;
            }

            const texto = textoListaSurtido();
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(texto);
                } else {
                    const area = document.createElement('textarea');
                    area.value = texto;
                    area.setAttribute('readonly', '');
                    area.style.cssText = 'position:fixed;opacity:0;pointer-events:none;';
                    document.body.appendChild(area);
                    area.select();
                    document.execCommand('copy');
                    area.remove();
                }
                alert('Lista de surtido copiada. Puedes pegarla en WhatsApp o enviarla al proveedor.');
            } catch (error) {
                alert('No se pudo copiar la lista en este navegador. Usa el botón Imprimir.');
            }
        }

        function imprimirListaSurtido() {
            const lineas = obtenerLineasSurtido();
            if (!lineas.length) {
                alert('No hay cantidades sugeridas para imprimir. Revisa los productos críticos manualmente.');
                return;
            }

            const diasObjetivo = configuracionSurtidoCriticos.diasEntrega + configuracionSurtidoCriticos.diasSeguridad;
            const filas = lineas.map((linea, indice) => `
                <tr>
                    <td>${indice + 1}</td>
                    <td><strong>${escaparTextoSurtido(linea.nombre)}</strong>${linea.marca ? `<br><small>${escaparTextoSurtido(linea.marca)}</small>` : ''}</td>
                    <td>${escaparTextoSurtido(linea.codigo || '—')}</td>
                    <td><strong>${formatearCantidadSurtido(linea.cantidad)} ${escaparTextoSurtido(linea.unidad)}</strong></td>
                </tr>
            `).join('');
            // Se abre una hoja vacía propia para poder escribir la lista antes
            // de imprimirla; no se navega hacia una URL externa.
            const ventana = window.open('', '_blank');
            if (!ventana) {
                alert('El navegador bloqueó la ventana de impresión. Permite ventanas emergentes e inténtalo de nuevo.');
                return;
            }
            ventana.document.write(`<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Lista de surtido</title><style>
                body{font-family:Arial,sans-serif;color:#172033;margin:28px} h1{margin:0 0 6px;font-size:22px} p{margin:0 0 18px;color:#475569;font-size:13px} table{width:100%;border-collapse:collapse}th,td{border:1px solid #cbd5e1;padding:9px;text-align:left;font-size:13px}th{background:#eaf1fb}small{color:#64748b}@media print{body{margin:14px}}
            </style></head><body><h1>Lista de surtido · Miscelánea Nuvi</h1><p>Cobertura objetivo: ${diasObjetivo} días (${configuracionSurtidoCriticos.diasEntrega} de entrega + ${configuracionSurtidoCriticos.diasSeguridad} de colchón). Generada: ${escaparTextoSurtido(new Date().toLocaleString('es-MX'))}</p><table><thead><tr><th>#</th><th>Producto</th><th>Código</th><th>Pedir</th></tr></thead><tbody>${filas}</tbody></table></body></html>`);
            ventana.document.close();
            ventana.focus();
            ventana.print();
        }

        async function imprimirListaSurtidoTermica() {
            const lineas = obtenerLineasSurtido();
            if (!lineas.length) {
                alert('No hay cantidades sugeridas para imprimir. Revisa los productos críticos manualmente.');
                return;
            }
            const boton = document.getElementById('btnImprimirTermicaSurtido');
            const textoOriginal = boton ? boton.textContent : '';
            if (boton) {
                boton.disabled = true;
                boton.textContent = 'Enviando…';
            }
            try {
                const respuesta = await fetch('imprimir_lista_surtido.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify({
                        lineas,
                        dias_entrega: configuracionSurtidoCriticos.diasEntrega,
                        dias_seguridad: configuracionSurtidoCriticos.diasSeguridad
                    })
                });
                const datos = await respuesta.json().catch(() => ({}));
                if (!respuesta.ok || !datos.success) {
                    throw new Error(datos.message || 'No se pudo enviar la lista a la térmica.');
                }
                alert(datos.message || 'Lista de surtido enviada a la térmica.');
            } catch (error) {
                alert(error.message || 'No se pudo enviar la lista a la térmica. Revisa que siga encendida antes de reintentar.');
            } finally {
                if (boton) {
                    boton.disabled = false;
                    boton.textContent = textoOriginal;
                }
            }
        }

        function cerrarModalCriticos() {
            const modal = document.getElementById('modalCriticos');
            if (modal) modal.style.display = 'none';
        }

        function renderProductosCriticos(lista) {
            if (!Array.isArray(lista) || lista.length === 0) {
                return '<p style="color:#64748b;">No hay productos con riesgo de agotarse según sus ventas de los últimos 30 días.</p>';
            }

            const escapar = escaparTextoSurtido;
            const cobertura = producto => {
                const ventas = Number(producto.ventas_30_dias || 0);
                const dias = Number(producto.dias_cobertura);
                if (ventas <= 0 || !Number.isFinite(dias)) return 'Sin ventas recientes';
                if (dias < 1) return '< 1 día';
                return `${dias.toFixed(dias < 10 ? 1 : 0)} días`;
            };
            const diasObjetivo = configuracionSurtidoCriticos.diasEntrega + configuracionSurtidoCriticos.diasSeguridad;

            return `
                <div class="criticos-configuracion">
                    <label>Proveedor surte en
                        <select id="criticosDiasEntrega">
                            <option value="3" ${configuracionSurtidoCriticos.diasEntrega === 3 ? 'selected' : ''}>3 días</option>
                            <option value="7" ${configuracionSurtidoCriticos.diasEntrega === 7 ? 'selected' : ''}>7 días</option>
                            <option value="14" ${configuracionSurtidoCriticos.diasEntrega === 14 ? 'selected' : ''}>14 días</option>
                        </select>
                    </label>
                    <label>Colchón de
                        <select id="criticosDiasSeguridad">
                            <option value="2" ${configuracionSurtidoCriticos.diasSeguridad === 2 ? 'selected' : ''}>2 días</option>
                            <option value="3" ${configuracionSurtidoCriticos.diasSeguridad === 3 ? 'selected' : ''}>3 días</option>
                            <option value="7" ${configuracionSurtidoCriticos.diasSeguridad === 7 ? 'selected' : ''}>7 días</option>
                        </select>
                    </label>
                    <button type="button" class="btn-lista-surtido" id="btnCopiarSurtido">Copiar lista</button>
                    <button type="button" class="btn-lista-surtido" id="btnImprimirSurtido">Imprimir</button>
                    <button type="button" class="btn-lista-surtido" id="btnImprimirTermicaSurtido">🖨 Térmica</button>
                </div>
                <p class="criticos-explicacion">Surtido sugerido para cubrir <strong>${diasObjetivo} días</strong>: plazo del proveedor más colchón. Se calcula con ventas reales de los últimos 30 días; los agotados siempre aparecen.</p>
                <table class="tabla-criticos">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Categoría</th>
                            <th>Stock</th>
                            <th>Vendidos 30d</th>
                            <th>Cobertura</th>
                            <th>Surtir sugerido</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${lista.map(p => {
                            const surtido = calcularSurtidoRecomendado(p);
                            const unidad = escapar(p.unidad_medida || 'unid.');
                            const sugerencia = surtido.cantidad === null
                                ? '<span class="surtido-sin-datos">Revisar manual</span>'
                                : surtido.cantidad <= 0
                                    ? '<span class="surtido-cubierto">Cobertura suficiente</span>'
                                    : `<strong class="surtido-cantidad">+${formatearCantidadSurtido(surtido.cantidad)}</strong><small>${unidad}</small>`;
                            return `
                            <tr>
                                <td>
                                    <strong>${escapar(p.nombre || 'Sin nombre')}</strong>
                                    <div style="font-size:.78rem;color:#64748b;">${escapar(p.marca || '')}${p.codigo_barras ? ' · ' + escapar(p.codigo_barras) : ''}</div>
                                </td>
                                <td>${escapar(p.categoria || 'Sin categoría')}</td>
                                <td>${parseFloat(p.stock || 0).toFixed(3)}</td>
                                <td>${parseFloat(p.ventas_30_dias || 0).toFixed(3)}<br><small>${parseFloat(p.venta_diaria || 0).toFixed(2)}/día</small></td>
                                <td>${cobertura(p)}</td>
                                <td class="surtido-sugerido">${sugerencia}</td>
                                <td><span class="chip-estado estado-${p.estado}">${String(p.estado || '').replace('_', ' ')}</span></td>
                            </tr>
                        `; }).join('')}
                    </tbody>
                </table>
            `;
        }

        function enlazarConfiguracionSurtidoCriticos() {
            const entrega = document.getElementById('criticosDiasEntrega');
            const seguridad = document.getElementById('criticosDiasSeguridad');
            const actualizar = () => {
                configuracionSurtidoCriticos = {
                    diasEntrega: Number(entrega?.value || 7),
                    diasSeguridad: Number(seguridad?.value || 3)
                };
                guardarConfiguracionSurtidoCriticos();
                const body = document.getElementById('modalCriticosBody');
                if (body) {
                    body.innerHTML = renderProductosCriticos(productosCriticosActuales);
                    enlazarConfiguracionSurtidoCriticos();
                }
            };
            if (entrega) entrega.addEventListener('change', actualizar);
            if (seguridad) seguridad.addEventListener('change', actualizar);
            const copiar = document.getElementById('btnCopiarSurtido');
            const imprimir = document.getElementById('btnImprimirSurtido');
            const imprimirTermica = document.getElementById('btnImprimirTermicaSurtido');
            if (copiar) copiar.addEventListener('click', copiarListaSurtido);
            if (imprimir) imprimir.addEventListener('click', imprimirListaSurtido);
            if (imprimirTermica) imprimirTermica.addEventListener('click', imprimirListaSurtidoTermica);
        }

        async function abrirModalCriticos() {
            const modal = document.getElementById('modalCriticos');
            const body = document.getElementById('modalCriticosBody');
            if (!modal || !body) return;

            modal.style.display = 'flex';
            body.innerHTML = '<p style="color:#64748b;">Cargando productos críticos...</p>';

            try {
                const res = await fetch('obtener_productos_criticos.php');
                const raw = await res.text();
                let data = null;
                try {
                    data = JSON.parse(raw);
                } catch (parseError) {
                    data = null;
                }

                let lista = [];
                if (Array.isArray(data)) {
                    lista = data;
                } else if (data && data.success && Array.isArray(data.productos)) {
                    lista = data.productos;
                } else if (data && data.success === false) {
                    throw new Error(data.message || 'No se pudieron cargar los productos críticos');
                }

                if (!lista.length && Array.isArray(window.productosInventario) && window.productosInventario.length) {
                    lista = window.productosInventario
                        .map(p => {
                            const stock = parseFloat(String(p.stock || '').replace(/\[.*\]$/, '').trim()) || 0;
                            let estado = 'estable';
                            if (stock <= 0) estado = 'agotado';
                            else if (stock <= 3) estado = 'por_agotarse';
                            else if (stock <= 8) estado = 'vigilancia';
                            return { ...p, stock, estado, ventas_30_dias: 0, venta_diaria: 0, dias_cobertura: null };
                        })
                        .filter(p => p.estado !== 'estable')
                        .sort((a, b) => a.stock - b.stock);
                }

                productosCriticosActuales = lista;
                body.innerHTML = renderProductosCriticos(productosCriticosActuales);
                enlazarConfiguracionSurtidoCriticos();
            } catch (error) {
                body.innerHTML = `<p style="color:#b91c1c;">${error.message || 'Error al cargar productos críticos'}</p>`;
            }
        }

        document.getElementById('btnVerCriticos').addEventListener('click', abrirModalCriticos);
    </script>
    <script src="js/buscar_productos.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/buscar_productos.js'); ?>" defer></script>
    <style>
        /* Botones estilizados para acciones */
        .btn-toggle {
            background: #f3f4f6;
            border: 1px solid #cbd5e1;
            padding: 6px 10px;
            border-radius: 6px;
            cursor: pointer;
        }
        .btn-toggle:hover { background: #e2e8f0; }
        .btn-eliminar {
            background: #ef4444;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 6px;
            cursor: pointer;
        }
        .btn-eliminar:hover { opacity: 0.9; }
        #tablaInventario tbody tr td { vertical-align: middle; }
        .btn-refrescar { margin-left: 12px; background:#06b6d4;color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer }
        .btn-refrescar:hover { opacity:0.95 }
        .btn-criticos { background:#dc2626;color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer;font-weight:700 }
        .btn-criticos:hover { opacity:0.95 }
        .btn-abastecimiento { display:inline-flex; align-items:center; justify-content:center; text-decoration:none; background:#0f766e; color:#fff; border:1px solid #0f766e; padding:8px 12px; border-radius:8px; cursor:pointer; font-weight:700; }
        .btn-abastecimiento:hover { background:#115e59; border-color:#115e59; }

        .modal-backdrop { position:fixed; inset:0; background:rgba(15, 23, 42, 0.55); display:none; align-items:center; justify-content:center; z-index:10000; padding:16px; }
        .modal-box { width:min(980px, 100%); max-height:86vh; overflow:hidden; background:#fff; border-radius:14px; box-shadow:0 22px 50px rgba(15, 23, 42, 0.35); display:flex; flex-direction:column; }
        .modal-header { display:flex; justify-content:space-between; align-items:center; padding:14px 16px; background:linear-gradient(135deg, #dc2626, #b91c1c); color:#fff; }
        .modal-body { padding:14px; overflow:auto; }

        .tabla-criticos { width:100%; border-collapse:collapse; min-width:760px; }
        .tabla-criticos th, .tabla-criticos td { padding:10px; border-bottom:1px solid #e2e8f0; text-align:left; }

        .chip-estado { display:inline-block; padding:4px 8px; border-radius:999px; font-size:.75rem; font-weight:700; }
        .estado-agotado { background:#fee2e2; color:#991b1b; }
        .estado-muy_urgente { background:#fef3c7; color:#92400e; }
        .estado-por_agotarse { background:#ffedd5; color:#9a3412; }
        .estado-stock_bajo { background:#e0f2fe; color:#0c4a6e; }
        .estado-vigilancia { background:#e0f2fe; color:#0c4a6e; }
        .criticos-explicacion { margin:0 0 12px; color:#475569; font-size:.88rem; line-height:1.45; }
        .criticos-configuracion { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:0 0 10px; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; }
        .criticos-configuracion label { display:flex; align-items:center; gap:6px; color:#334155; font-size:.84rem; font-weight:700; }
        .criticos-configuracion select { width:auto; padding:6px 8px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; color:#0f172a; font:inherit; }
        .btn-lista-surtido { width:auto; padding:7px 10px; border:1px solid #2563eb; border-radius:7px; background:#2563eb; color:#fff; cursor:pointer; font:700 .82rem inherit; }
        .btn-lista-surtido:hover { background:#1d4ed8; }
        .surtido-sugerido { white-space:nowrap; }
        .surtido-sugerido small { display:block; color:#64748b; margin-top:2px; }
        .surtido-cantidad { color:#047857; font-size:1rem; }
        .surtido-sin-datos { color:#64748b; font-size:.83rem; }
        .surtido-cubierto { color:#0369a1; font-size:.83rem; font-weight:700; }

        /* El calculador de precios se genera desde JavaScript. Estas reglas
           lo mantienen legible aunque los estilos base de cada botón se
           apliquen en línea. */
        html.nuvi-tema-oscuro #tablaInventario tbody tr:hover > td { background:#253247 !important; }
        html.nuvi-tema-oscuro #tablaInventario .col-nombre-unidad::after { color:#bfdbfe !important; }
        html.nuvi-tema-oscuro #tablaInventario .margen-tools {
            background:#172b42 !important;
            border-color:#3b82f6 !important;
            box-shadow:inset 0 1px 0 rgba(191,219,254,.08);
        }
        html.nuvi-tema-oscuro #tablaInventario .margen-sugerido-label { color:#bfdbfe !important; }
        html.nuvi-tema-oscuro #tablaInventario .margen-buttons .btn-toggle,
        html.nuvi-tema-oscuro #tablaInventario .td-acciones > .btn-toggle {
            background:#253247 !important;
            color:#f8fafc !important;
            border-color:#52708f !important;
        }
        html.nuvi-tema-oscuro #tablaInventario .margen-buttons .btn-toggle:hover,
        html.nuvi-tema-oscuro #tablaInventario .margen-buttons .btn-toggle:focus-visible,
        html.nuvi-tema-oscuro #tablaInventario .td-acciones > .btn-toggle:hover {
            background:#2563eb !important;
            color:#ffffff !important;
            border-color:#93c5fd !important;
        }
        html.nuvi-tema-oscuro #tablaInventario .editable:focus { background:#0f172a !important; }
        html.nuvi-tema-oscuro .criticos-configuracion { background:#1e293b !important; border-color:#475569 !important; }
        html.nuvi-tema-oscuro .criticos-configuracion label { color:#e2e8f0 !important; }
        html.nuvi-tema-oscuro .criticos-configuracion select { background:#0f172a !important; color:#f8fafc !important; border-color:#64748b !important; }
        html.nuvi-tema-oscuro .btn-lista-surtido { background:#2563eb !important; color:#fff !important; border-color:#93c5fd !important; }
        html.nuvi-tema-oscuro .btn-abastecimiento { background:#0f766e !important; color:#fff !important; border-color:#2dd4bf !important; }
        html.nuvi-tema-oscuro .surtido-cantidad { color:#6ee7b7 !important; }
        html.nuvi-tema-oscuro .surtido-sugerido small,
        html.nuvi-tema-oscuro .surtido-sin-datos { color:#cbd5e1 !important; }
        html.nuvi-tema-oscuro .surtido-cubierto { color:#7dd3fc !important; }
    </style>
</body>

</html>
