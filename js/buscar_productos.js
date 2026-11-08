// Archivo: js/buscar_productos.js
let timeout = null;
let controller = null; // AbortController para cancelar peticiones anteriores
const MIN_QUERY = 2;
const DEBOUNCE_MS = 300;
const RENDER_BATCH_SIZE = 120;
let renderToken = 0;
const FETCH_TIMEOUT_MS = 6000;
const INVENTARIO_CACHE_KEY = 'cache_buscar_productos_v2';
let inventarioSearchRevision = 0;
let mostrarSoloStockNegativo = false;
const INVENTARIO_LONGITUDES_CODIGO = [8, 12, 13, 14];
const INVENTARIO_INTERVALO_LECTOR_BLUETOOTH_MS = 260;
const INVENTARIO_ESPERA_LECTOR_MS = 85;

function invalidarCatalogoPos() {
    try {
        localStorage.removeItem('nuvi_pos_catalogo_cache_v2');
        localStorage.setItem('nuvi_productos_actualizados', String(Date.now()));
    } catch (e) {}
}

function esCodigoLectorInventario(valor) {
    return INVENTARIO_LONGITUDES_CODIGO.includes(String(valor || '').trim().length);
}

function digitoEventoLectorInventario(evento) {
    if (/^\d$/.test(evento.key)) return evento.key;
    const coincidencia = String(evento.code || '').match(/^(?:Digit|Numpad)(\d)$/);
    return coincidencia ? coincidencia[1] : '';
}

function esCampoEditableInventario(elemento) {
    return elemento instanceof Element && Boolean(elemento.closest('input, textarea, select, [contenteditable="true"]'));
}

// El lector HID se comporta como teclado. En inventario sólo redirigimos la
// lectura cuando no se está editando una celda ni el cuadro de código. El
// propio buscador sí se captura: al terminar una lectura se reemplaza completo
// el código anterior, así las lecturas consecutivas no se quedan pegadas.
document.addEventListener('DOMContentLoaded', () => {
    const buscador = document.getElementById('inputBusqueda') || document.getElementById('buscador');
    if (!buscador || !document.getElementById('cuerpoTabla')) return;
    let captura = null;
    let temporizador = null;

    const cancelar = () => {
        if (temporizador) clearTimeout(temporizador);
        temporizador = null;
        captura = null;
    };
    const procesar = () => {
        const codigo = captura?.codigo || '';
        cancelar();
        if (!esCodigoLectorInventario(codigo)) return;
        buscador.value = codigo;
        // El inventario visible tiene un filtro inmediato y la búsqueda grande
        // usa keyup con debounce; emitimos ambos porque el valor se asignó por
        // código y el navegador no genera esos eventos automáticamente.
        buscador.dispatchEvent(new Event('input', { bubbles: true }));
        buscador.dispatchEvent(new Event('keyup', { bubbles: true }));
        buscador.focus({ preventScroll: true });
    };
    const programar = () => {
        if (!captura || !esCodigoLectorInventario(captura.codigo)) return;
        if (temporizador) clearTimeout(temporizador);
        temporizador = setTimeout(procesar, INVENTARIO_ESPERA_LECTOR_MS);
    };

    document.addEventListener('keydown', evento => {
        if (evento.defaultPrevented || evento.ctrlKey || evento.altKey || evento.metaKey || evento.isComposing) return;
        const modalCodigo = document.getElementById('modalEditarCodigo');
        const dentroDelBuscador = evento.target === buscador
            || (evento.target instanceof Element && evento.target.closest('#inputBusqueda, #buscador') === buscador);
        if ((modalCodigo && window.getComputedStyle(modalCodigo).display !== 'none')
            || (!dentroDelBuscador && esCampoEditableInventario(evento.target))) {
            cancelar();
            return;
        }

        const digito = digitoEventoLectorInventario(evento);
        const ahora = performance.now();
        if (digito) {
            const continua = captura && (ahora - captura.ultimaTecla) <= INVENTARIO_INTERVALO_LECTOR_BLUETOOTH_MS;
            if (!continua) captura = { codigo: '', ultimaTecla: ahora };
            captura.codigo += digito;
            captura.ultimaTecla = ahora;
            if (captura.codigo.length > Math.max(...INVENTARIO_LONGITUDES_CODIGO)) {
                cancelar();
                return;
            }
            programar();
            return;
        }

        if ((evento.key === 'Enter' || evento.key === 'Tab') && captura && esCodigoLectorInventario(captura.codigo)) {
            evento.preventDefault();
            evento.stopPropagation();
            procesar();
            return;
        }
        cancelar();
    }, true);
});

function parseEditableNumber(rawValue) {
    if (rawValue === null || rawValue === undefined) return 0;
    const normalized = String(rawValue)
        .replace(',', '.')
        .replace(/[^0-9.-]/g, '')
        .trim();
    const n = parseFloat(normalized);
    return isNaN(n) ? 0 : n;
}

// La base conserva hasta cuatro decimales para granel, pero en inventario se
// muestran sólo los necesarios: 12 en vez de 12.0000 y 0.0025 sin alterarlo.
function formatearPrecioInventario(valor) {
    const numero = parseEditableNumber(valor);
    if (!Number.isFinite(numero)) return '';
    return numero.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
}

function esUnidadPrecioFraccionario(unidad) {
    return ['g', 'ml'].includes(String(unidad || '').toLowerCase());
}

function calcularPrecioSugeridoInventario(precioCompra, margenPorciento, unidad = '') {
    const costo = parseEditableNumber(precioCompra);
    if (costo <= 0) return 0;
    const bruto = costo * (1 + (margenPorciento / 100));
    return esUnidadPrecioFraccionario(unidad)
        ? Math.ceil(bruto * 10000) / 10000
        : Math.ceil(bruto);
}

function actualizarEtiquetaSugerida(fila, tdCompra) {
    if (!fila || !tdCompra) return;
    const label = fila.querySelector('.margen-sugerido-label');
    if (!label) return;
    const unidad = fila.dataset.unidad || '';
    const sugerido = calcularPrecioSugeridoInventario(tdCompra.innerText, 25, unidad);
    const texto = formatearPrecioInventario(sugerido);
    label.textContent = sugerido > 0 ? `Sugerido base 25%: $${texto}` : 'Sugerido base 25%: $0';
}

function aplicarMargenInventario(fila, tdCompra, tdVenta, idProducto, margenPorciento) {
    if (!fila || !tdCompra || !tdVenta) return;
    const sugerido = calcularPrecioSugeridoInventario(tdCompra.innerText, margenPorciento, fila.dataset.unidad || '');
    if (sugerido <= 0) {
        alert('Primero captura un precio de compra válido.');
        return;
    }
    tdVenta.innerText = sugerido;
    guardarEdicion(tdVenta, idProducto, 'precio_venta');
    flashRow(fila);
}

function fetchWithTimeout(url, options = {}, timeoutMs = FETCH_TIMEOUT_MS) {
    const timeoutController = new AbortController();
    const timer = setTimeout(() => timeoutController.abort(), timeoutMs);
    const signal = options.signal || timeoutController.signal;

    return fetch(url, { ...options, signal })
        .finally(() => clearTimeout(timer));
}

function normalizarTextoBusqueda(valor) {
    return String(valor ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

function palabrasBusquedaInventario(termino) {
    return normalizarTextoBusqueda(termino).split(' ').filter(Boolean);
}

function datosBusquedaInventario(producto) {
    return {
        id: normalizarTextoBusqueda(producto?.id),
        nombre: normalizarTextoBusqueda(producto?.nombre),
        codigo: normalizarTextoBusqueda(producto?.codigo_barras),
        marca: normalizarTextoBusqueda(producto?.marca),
        categoria: normalizarTextoBusqueda(producto?.categoria)
    };
}

// Da prioridad a lo que el usuario escribió exactamente, pero admite palabras
// en cualquier orden y acentos. El stock sólo decide empates, no "esconde" un
// producto que coincide mejor.
function puntuarBusquedaInventario(producto, termino) {
    const terminoNormalizado = normalizarTextoBusqueda(termino);
    const palabras = palabrasBusquedaInventario(termino);
    if (!terminoNormalizado || !palabras.length) return 0;

    const datos = datosBusquedaInventario(producto);
    const textoCompleto = `${datos.nombre} ${datos.marca} ${datos.categoria} ${datos.codigo} ${datos.id}`.trim();
    if (!palabras.every(palabra => textoCompleto.includes(palabra))) return -1;

    let puntuacion = palabras.length * 100;
    if (datos.codigo === terminoNormalizado) puntuacion += 100000;
    if (datos.id === terminoNormalizado) puntuacion += 95000;
    if (datos.nombre === terminoNormalizado) puntuacion += 90000;
    else if (datos.nombre.startsWith(terminoNormalizado)) puntuacion += 50000;
    else if (datos.nombre.includes(terminoNormalizado)) puntuacion += 9000;

    palabras.forEach(palabra => {
        if (datos.nombre.startsWith(palabra)) puntuacion += 700;
        else if (datos.nombre.includes(palabra)) puntuacion += 350;
        if (datos.codigo.startsWith(palabra)) puntuacion += 500;
        else if (datos.codigo.includes(palabra)) puntuacion += 250;
        if (datos.marca.includes(palabra)) puntuacion += 140;
        if (datos.categoria.includes(palabra)) puntuacion += 80;
    });

    return puntuacion;
}

function ordenarResultadosInventario(productos, termino = '') {
    const lista = [...(productos || [])];
    if (!normalizarTextoBusqueda(termino)) return ordenarProductosInventario(lista);

    return lista
        .map(producto => ({ producto, puntuacion: puntuarBusquedaInventario(producto, termino) }))
        .filter(resultado => resultado.puntuacion >= 0)
        .sort((a, b) => {
            if (b.puntuacion !== a.puntuacion) return b.puntuacion - a.puntuacion;
            const stockA = parseFloat(a.producto.stock) || 0;
            const stockB = parseFloat(b.producto.stock) || 0;
            if (stockA !== stockB) return stockA - stockB;
            return String(a.producto.nombre || '').localeCompare(String(b.producto.nombre || ''), 'es');
        })
        .map(resultado => resultado.producto);
}

window.nuviOrdenarResultadosInventario = ordenarResultadosInventario;

function renderTablaError(message) {
    const tbody = document.getElementById('cuerpoTabla');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="9" style="padding:14px;color:#b91c1c;font-weight:600;">${message}</td></tr>`;
}

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('inputBusqueda') || document.getElementById('buscador');
    if (!input) return;

    const restorePlaceholder = (el, original) => { if (el) el.placeholder = original; };

    if (document.getElementById('cuerpoTabla')) {
        actualizarAvisoStockNegativo();
        cargarProductosInventario();
        const parametros = new URLSearchParams(window.location.search);
        const queryInicial = parametros.get('q') || '';
        const estado = parametros.get('estado') || '';
        if (queryInicial) {
            input.value = queryInicial;
            cargarProductosInventario(queryInicial);
        }
        if (estado === 'codigo_duplicado') {
            const mensaje = document.getElementById('mensajeInventario');
            if (mensaje) {
                mensaje.textContent = 'Este código ya está registrado. Se muestra el producto existente para que puedas revisarlo o editarlo.';
                mensaje.style.display = 'block';
                mensaje.style.background = '#fffbeb';
                mensaje.style.border = '1px solid #fcd34d';
                mensaje.style.color = '#92400e';
            }
        }
    }

    const botonNegativos = document.getElementById('btnStockNegativo');
    if (botonNegativos) botonNegativos.addEventListener('click', () => {
        clearTimeout(timeout);
        if (controller) controller.abort();
        mostrarSoloStockNegativo = !mostrarSoloStockNegativo;
        botonNegativos.setAttribute('aria-pressed', String(mostrarSoloStockNegativo));
        botonNegativos.textContent = mostrarSoloStockNegativo ? 'Ver todo el inventario' : 'Ver existencias negativas';
        input.value = '';
        cargarProductosInventario();
    });

    input.addEventListener('keyup', function() {
        clearTimeout(timeout);

        const originalPlaceholder = this.placeholder || '';

        timeout = setTimeout(async () => {
            const query = this.value.trim();
            if (query.length < MIN_QUERY) {
                if (document.getElementById('cuerpoTabla')) {
                    cargarProductosInventario(query);
                }
                return restorePlaceholder(this, originalPlaceholder);
            }

            // Cancelar petición previa
            if (controller) controller.abort();
            controller = new AbortController();
            const signal = controller.signal;

            // Indicador simple: cambiar placeholder mientras se busca
            this.placeholder = 'Buscando...';

            const revisionActual = ++inventarioSearchRevision;
            try {
                // Chequear cache local primero (exact match)
                const cacheRaw = localStorage.getItem(INVENTARIO_CACHE_KEY);
                let cache = { queries: {}, updatedAt: 0 };
                try { cache = cacheRaw ? JSON.parse(cacheRaw) : cache; } catch (e) {}

                if (!mostrarSoloStockNegativo && cache.queries && cache.queries[query]) {
                    const cached = cache.queries[query];
                    if (Array.isArray(cached.results)) {
                        // Si no pedimos inactivos, filtrar los resultados en cache
                        const includeInactiveLocal = (window && window.BUSCAR_INCLUDE_INACTIVE) ? true : false;
                        let toShow = cached.results;
                        if (!includeInactiveLocal) {
                            toShow = (cached.results || []).filter(p => !(p.activo !== undefined && (p.activo === '0' || p.activo === 0 || p.activo === 'false')));
                        }
                        renderizarTabla(ordenarResultadosInventario(toShow, query));
                    }
                } else if (!mostrarSoloStockNegativo) {
                    // If we have prefix entries, try to show quick local matches
                    const prefixMatches = Object.keys(cache.queries).filter(k => query.startsWith(k)).slice(-5);
                    for (const k of prefixMatches) {
                        const r = cache.queries[k].results || [];
                        const includeInactiveLocal = (window && window.BUSCAR_INCLUDE_INACTIVE) ? true : false;
                        const localFiltered = r.filter(p => puntuarBusquedaInventario(p, query) >= 0);
                        const finalFiltered = includeInactiveLocal ? localFiltered : localFiltered.filter(p => !(p.activo !== undefined && (p.activo === '0' || p.activo === 0 || p.activo === 'false')));
                        if (finalFiltered.length > 0) {
                            renderizarTabla(ordenarResultadosInventario(finalFiltered, query));
                            break;
                        }
                    }
                }

                const includeQuery = (window && window.BUSCAR_INCLUDE_INACTIVE) ? '&include_inactive=1' : '';
                const filtroStock = mostrarSoloStockNegativo ? '&stock_negativo=1' : '';
                const res = await fetchWithTimeout(`api/buscar_productos.php?q=${encodeURIComponent(query)}${includeQuery}${filtroStock}`, { signal });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const payload = await res.json();
                if (payload && payload.status === 'error') {
                    renderTablaError(payload.message || 'Error al cargar inventario desde el servidor.');
                    return;
                }
                const data = payload && payload.results ? payload.results : payload;

                // Si no pedimos inactivos, filtrar los resultados provenientes del servidor
                const includeInactiveLocal = (window && window.BUSCAR_INCLUDE_INACTIVE) ? true : false;
                const dataToRender = includeInactiveLocal ? data : (data || []).filter(p => !(p.activo !== undefined && (p.activo === '0' || p.activo === 0 || p.activo === 'false')));

                if (revisionActual !== inventarioSearchRevision) return;

                // Guardar en cache (simple, por query exacta)
                cache.queries = cache.queries || {};
                if (!mostrarSoloStockNegativo) cache.queries[query] = { results: data, ts: Date.now() };
                cache.updatedAt = Date.now();
                // Mantener solo últimas 100 queries
                const keys = Object.keys(cache.queries).slice(-100);
                const newQueries = {};
                keys.forEach(k => newQueries[k] = cache.queries[k]);
                cache.queries = newQueries;
                try { localStorage.setItem(INVENTARIO_CACHE_KEY, JSON.stringify(cache)); } catch (e) { /* posible quota */ }

                if (typeof renderizarTabla === 'function') {
                    renderizarTabla(ordenarResultadosInventario(dataToRender, query));
                } else if (typeof mostrarSugerencias === 'function') {
                    mostrarSugerencias(ordenarResultadosInventario(dataToRender, query));
                } else {
                    console.warn('No existe ni renderizarTabla ni mostrarSugerencias para mostrar resultados.');
                }
            } catch (err) {
                if (err.name === 'AbortError') return; // petición cancelada por nueva búsqueda
                console.error('Error al buscar productos:', err);
                // Evitar alertas bloqueantes cuando la red está lenta; la UI conserva el último render/caché.
            } finally {
                restorePlaceholder(this, originalPlaceholder);
            }
        }, DEBOUNCE_MS);
    });
    // Inyectar estilos para pulso de actualización una vez
    if (!document.getElementById('buscar-productos-pulse-style')) {
        const style = document.createElement('style');
        style.id = 'buscar-productos-pulse-style';
        style.textContent = `
        @keyframes pulse-update { 0% { box-shadow: 0 0 0 rgba(16,185,129,0.0); } 50% { box-shadow: 0 0 18px rgba(16,185,129,0.9); } 100% { box-shadow: 0 0 0 rgba(16,185,129,0.0); } }
        .pulse-update { animation: pulse-update 0.9s ease-in-out; }
        `;
        document.head.appendChild(style);
    }
    // Exponer función para limpiar cache desde la UI
    window.clearBuscarCache = clearBuscarCache;
    // Botón de refrescar cache si existe en esta página
    const btnRef = document.getElementById('btnRefrescarCache');
    if (btnRef) btnRef.addEventListener('click', () => { clearBuscarCache(true); });
});

function ordenarProductosInventario(productos) {
    return [...(productos || [])].sort((a, b) => {
        const stockA = parseFloat(a.stock) || 0;
        const stockB = parseFloat(b.stock) || 0;
        if (stockA !== stockB) return stockA - stockB;
        const nombreA = (a.nombre || '').toLowerCase();
        const nombreB = (b.nombre || '').toLowerCase();
        return nombreA.localeCompare(nombreB);
    });
}

async function cargarProductosInventario(query = '') {
    const revisionActual = ++inventarioSearchRevision;
    const includeInactive = (window && window.BUSCAR_INCLUDE_INACTIVE) ? '&include_inactive=1' : '';
    const q = encodeURIComponent(query.trim());
    const filtroStock = mostrarSoloStockNegativo ? '&stock_negativo=1' : '';
    try {
        const res = await fetchWithTimeout(`api/buscar_productos.php?q=${q}${includeInactive}${filtroStock}`);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const payload = await res.json();
        if (payload && payload.status === 'error') throw new Error(payload.message || 'Error al cargar inventario');
        const data = payload && payload.results ? payload.results : payload;
        if (revisionActual !== inventarioSearchRevision) return;
        const dataToRender = ordenarResultadosInventario(data || [], query);
        if (typeof renderizarTabla === 'function') {
            renderizarTabla(dataToRender);
        }
    } catch (err) {
        console.error('Error al cargar inventario:', err);
        renderTablaError('No se pudo cargar el inventario. Revisa la conexión e inténtalo de nuevo.');
    }
}

async function actualizarAvisoStockNegativo() {
    const aviso = document.getElementById('avisoStockNegativo');
    if (!aviso) return;
    try {
        const respuesta = await fetchWithTimeout('api/buscar_productos.php?stock_negativo=1');
        if (!respuesta.ok) throw new Error('HTTP ' + respuesta.status);
        const datos = await respuesta.json();
        if (datos.status !== 'ok' || !Array.isArray(datos.results)) throw new Error(datos.message || 'Respuesta inválida');
        const cantidad = datos.results.length;
        aviso.textContent = cantidad
            ? `${cantidad} producto(s) tienen existencias negativas. Revisa y actualiza su inventario.`
            : '';
        aviso.style.display = cantidad ? 'block' : 'none';
    } catch (error) {
        console.error('No se pudo consultar el stock negativo:', error);
        aviso.textContent = 'No se pudo comprobar si hay existencias negativas. Revisa la conexión.';
        aviso.style.display = 'block';
    }
}

// RENDERIZADO DE TABLA, EDICIÓN INLINE Y ORDENAMIENTO
function renderizarTabla(productos) {
    const tbody = document.getElementById('cuerpoTabla');
    if (!tbody) return;
    tbody.innerHTML = '';
    window.productosInventario = Array.isArray(productos) ? productos.slice() : [];

    const currentRenderToken = ++renderToken;

    function buildRow(p) {
        const fila = document.createElement('tr');
        fila.dataset.unidad = p.unidad_medida || 'pza';
        if (p.activo !== undefined && (p.activo === '0' || p.activo === 0 || p.activo === 'false')) {
            fila.style.opacity = '0.5';
        }

        const tdId = document.createElement('td');
        tdId.className = 'col-id';
        tdId.textContent = p.id;
        fila.appendChild(tdId);

        const tdCod = document.createElement('td');
        tdCod.className = 'col-codigo';
        tdCod.textContent = p.codigo_barras || '';
        fila.appendChild(tdCod);

        const tdNombre = document.createElement('td');
        tdNombre.className = 'col-nombre-unidad';
        tdNombre.dataset.unidad = p.unidad_medida || 'pza';
        tdNombre.contentEditable = true;
        tdNombre.textContent = p.nombre || '';
        tdNombre.addEventListener('blur', () => guardarEdicion(tdNombre, p.id, 'nombre'));
        fila.appendChild(tdNombre);

        const tdMarca = document.createElement('td');
        tdMarca.className = 'col-marca';
        tdMarca.contentEditable = true;
        tdMarca.title = 'Marca, empresa o proveedor habitual';
        tdMarca.textContent = p.marca || '';
        tdMarca.addEventListener('blur', () => guardarEdicion(tdMarca, p.id, 'marca'));
        fila.appendChild(tdMarca);

        const tdPrecioV = document.createElement('td');
        tdPrecioV.contentEditable = true;
        tdPrecioV.textContent = formatearPrecioInventario(p.precio_venta !== undefined ? p.precio_venta : (p.precio || '0'));
        tdPrecioV.title = `Precio por ${p.unidad_medida || 'pza'}`;
        tdPrecioV.addEventListener('blur', () => guardarEdicion(tdPrecioV, p.id, 'precio_venta'));
        fila.appendChild(tdPrecioV);

        const tdPrecioC = document.createElement('td');
        tdPrecioC.contentEditable = true;
        tdPrecioC.textContent = p.precio_compra !== undefined && p.precio_compra !== null && p.precio_compra !== ''
            ? formatearPrecioInventario(p.precio_compra)
            : '';
        tdPrecioC.title = `Costo por ${p.unidad_medida || 'pza'}`;
        tdPrecioC.addEventListener('blur', () => {
            guardarEdicion(tdPrecioC, p.id, 'precio_compra');
            // Cambiar el costo no debe alterar el precio de venta: el cajero
            // decide si aplica uno de los márgenes con los botones explícitos.
            actualizarEtiquetaSugerida(fila, tdPrecioC);
        });
        fila.appendChild(tdPrecioC);

        const tdStock = document.createElement('td');
        tdStock.contentEditable = true;
        tdStock.textContent = p.stock !== undefined ? p.stock : '';
        tdStock.title = `Stock registrado en ${p.unidad_medida || 'pza'}`;
        tdStock.addEventListener('focus', () => {
            tdStock.textContent = tdStock.dataset.stockGuardado;
        });
        tdStock.addEventListener('blur', () => guardarEdicion(tdStock, p.id, 'stock'));
        const stockNum = parseFloat(p.stock) || 0;
        updateStockVisual(tdStock, stockNum);
        fila.appendChild(tdStock);

        const tdCategoria = document.createElement('td');
        tdCategoria.className = 'col-categoria';
        tdCategoria.contentEditable = true;
        tdCategoria.textContent = p.categoria || '';
        tdCategoria.addEventListener('blur', () => guardarEdicion(tdCategoria, p.id, 'categoria'));
        fila.appendChild(tdCategoria);

        const tdAcc = document.createElement('td');
        tdAcc.className = 'td-acciones';
        const btnCodigo = document.createElement('button');
        btnCodigo.type = 'button';
        btnCodigo.textContent = 'Código';
        btnCodigo.title = 'Editar o escanear código de barras';
        btnCodigo.className = 'btn-toggle';
        btnCodigo.style.marginRight = '8px';
        btnCodigo.addEventListener('click', () => abrirEditorCodigoBarras(p, tdCod, fila));

        const btnToggle = document.createElement('button');
        btnToggle.textContent = (p.activo === '0' || p.activo === 0) ? 'Reactivar' : 'Pausar';
        btnToggle.style.marginRight = '8px';
        btnToggle.className = 'btn-toggle';
        btnToggle.addEventListener('click', () => toggleActivo(p.id, fila, btnToggle));

        const btnEliminar = document.createElement('button');
        btnEliminar.textContent = 'Eliminar';
        btnEliminar.className = 'btn-eliminar';
        btnEliminar.style.marginLeft = '4px';
        btnEliminar.addEventListener('click', () => eliminarProducto(p.id, fila));

        const margenTools = document.createElement('div');
        margenTools.className = 'margen-tools';
        margenTools.style.display = 'grid';
        margenTools.style.gap = '6px';
        margenTools.style.marginTop = '8px';
        margenTools.style.padding = '8px';
        margenTools.style.border = '1px solid #bfdbfe';
        margenTools.style.borderRadius = '8px';
        margenTools.style.background = '#eff6ff';

        const margenLabel = document.createElement('div');
        margenLabel.className = 'margen-sugerido-label';
        margenLabel.style.fontSize = '0.78rem';
        margenLabel.style.color = '#1e40af';
        margenLabel.style.fontWeight = '700';
        margenTools.appendChild(margenLabel);

        const margenBtnsWrap = document.createElement('div');
        margenBtnsWrap.className = 'margen-buttons';
        margenBtnsWrap.style.display = 'flex';
        margenBtnsWrap.style.flexWrap = 'wrap';
        margenBtnsWrap.style.gap = '6px';

        // El 25% también es una acción explícita. Antes sólo aparecía como
        // texto sugerido, lo que hacía parecer que faltaba en tablets.
        [25, 30, 35, 50].forEach((margen) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn-toggle';
            b.style.padding = '6px 8px';
            b.style.fontSize = '0.78rem';
            b.style.fontWeight = '700';
            b.textContent = `+${margen}%`;
            b.addEventListener('click', () => aplicarMargenInventario(fila, tdPrecioC, tdPrecioV, p.id, margen));
            margenBtnsWrap.appendChild(b);
        });
        margenTools.appendChild(margenBtnsWrap);

        tdAcc.appendChild(btnCodigo);
        tdAcc.appendChild(btnToggle);
        tdAcc.appendChild(btnEliminar);
        tdAcc.appendChild(margenTools);
        fila.appendChild(tdAcc);

        actualizarEtiquetaSugerida(fila, tdPrecioC);

        return fila;
    }

    function renderBatch(startIndex) {
        if (currentRenderToken !== renderToken) return;
        const fragment = document.createDocumentFragment();
        const endIndex = Math.min(startIndex + RENDER_BATCH_SIZE, productos.length);

        for (let i = startIndex; i < endIndex; i++) {
            fragment.appendChild(buildRow(productos[i]));
        }

        tbody.appendChild(fragment);

        if (endIndex < productos.length) {
            requestAnimationFrame(() => renderBatch(endIndex));
        }
    }

    renderBatch(0);

    // Re-bind sorting handlers to headers
    const headers = document.querySelectorAll('#tabla-inventario thead th[data-field], #tablaInventario thead th[data-field]');
    headers.forEach((th, idx) => {
        th.style.cursor = 'pointer';
        th.onclick = () => ordenarTablaPorCampo(th.getAttribute('data-field'));
    });
}

// Actualiza el contenido del TD del stock con el punto de color y el aviso
function updateStockVisual(tdStock, stockNum) {
    const num = isNaN(stockNum) ? 0 : Number(stockNum);
    let color = '#10b981'; // verde por defecto
    let aviso = '';
    if (num < 0) { color = '#b91c1c'; aviso = ' [REVISAR EXISTENCIAS]'; }
    else if (num === 0) { color = '#ef4444'; aviso = ' [AGOTADO]'; }
    else if (num <= 3) { color = '#f59e0b'; aviso = ' [SURTIR]'; }

    tdStock.innerHTML = '';
    const spanDot = document.createElement('span');
    spanDot.style.display = 'inline-block'; spanDot.style.width = '10px'; spanDot.style.height = '10px'; spanDot.style.borderRadius = '50%'; spanDot.style.background = color; spanDot.style.marginRight = '8px';
    tdStock.appendChild(spanDot);
    tdStock.appendChild(document.createTextNode((isNaN(num) ? '' : num) + aviso));
    tdStock.dataset.stockGuardado = String(num);
    tdStock.contentEditable = true;
}

// Efecto rápido de brillo/actualización cuando algo cambia
function flashRow(fila) {
    if (!fila) return;
    // Añadir clase que aplica la animación de pulso
    fila.classList.remove('pulse-update');
    // Forzar reflow para reiniciar la animación si ya existe
    void fila.offsetWidth;
    fila.classList.add('pulse-update');
    // Quitar la clase al terminar (safety)
    setTimeout(() => { fila.classList.remove('pulse-update'); }, 1000);
}

// Limpiar cache local y relanzar búsqueda actual
function clearBuscarCache(showToast) {
    try {
        localStorage.removeItem('cache_buscar_productos_v1');
        localStorage.removeItem(INVENTARIO_CACHE_KEY);
    } catch (e) {}
    // Opcional: forzar nueva búsqueda con el valor actual del input
    const input = document.getElementById('inputBusqueda') || document.getElementById('buscador');
    const q = input ? input.value.trim() : '';
    if (showToast) {
        // pequeño toast
        const t = document.createElement('div'); t.textContent = 'Cache limpiado';
        Object.assign(t.style, {position:'fixed',right:'20px',bottom:'20px',background:'#064e3b',color:'#fff',padding:'8px 12px',borderRadius:'8px',zIndex:9999});
        document.body.appendChild(t); setTimeout(()=>t.remove(),1500);
    }
    if (q && q.length >= MIN_QUERY) {
        // forzar búsqueda inmediata
        if (controller) controller.abort();
        controller = new AbortController();
        const signal = controller.signal;
        const revisionActual = ++inventarioSearchRevision;
        const includeQuery = (window && window.BUSCAR_INCLUDE_INACTIVE) ? '&include_inactive=1' : '';
        fetchWithTimeout(`api/buscar_productos.php?q=${encodeURIComponent(q)}${includeQuery}`, { signal })
            .then(res => res.json())
            .then(payload => {
                if (payload && payload.status === 'error') {
                    renderTablaError(payload.message || 'Error al buscar productos.');
                    return;
                }
                const data = payload && payload.results ? payload.results : payload;
                const includeInactiveLocal = (window && window.BUSCAR_INCLUDE_INACTIVE) ? true : false;
                const dataToRender = includeInactiveLocal ? data : (data || []).filter(p => !(p.activo !== undefined && (p.activo === '0' || p.activo === 0 || p.activo === 'false')));
                if (revisionActual !== inventarioSearchRevision) return;
                if (typeof renderizarTabla === 'function') renderizarTabla(ordenarResultadosInventario(dataToRender, q));
            }).catch(err => { if (err.name !== 'AbortError') console.error('Error re-buscando tras limpiar cache:', err); });
    } else {
        cargarProductosInventario();
    }
}

async function leerRespuestaJson(response) {
    const text = await response.text();
    if (!text) return {};
    try {
        return JSON.parse(text);
    } catch (e) {
        return { status: 'error', message: text };
    }
}

let editorCodigoBarrasActivo = null;

function mostrarMensajeInventario(texto, tipo = 'ok') {
    const mensaje = document.getElementById('mensajeInventario');
    if (!mensaje) return;
    const colores = tipo === 'error'
        ? { fondo: '#fef2f2', borde: '#fca5a5', texto: '#b91c1c' }
        : { fondo: '#ecfdf5', borde: '#86efac', texto: '#166534' };
    mensaje.textContent = texto;
    mensaje.style.display = 'block';
    mensaje.style.background = colores.fondo;
    mensaje.style.border = `1px solid ${colores.borde}`;
    mensaje.style.color = colores.texto;
}

function cerrarEditorCodigoBarras() {
    const modal = document.getElementById('modalEditarCodigo');
    if (modal) modal.style.display = 'none';
    editorCodigoBarrasActivo = null;
}

function abrirEditorCodigoBarras(producto, celdaCodigo, fila) {
    const modal = document.getElementById('modalEditarCodigo');
    const input = document.getElementById('inputEditarCodigo');
    const descripcion = document.getElementById('descripcionEditarCodigo');
    if (!modal || !input || !descripcion) return;

    editorCodigoBarrasActivo = {
        id: Number(producto.id),
        producto: String(producto.nombre || 'Producto'),
        celdaCodigo,
        fila
    };
    descripcion.textContent = `${editorCodigoBarrasActivo.producto} · ID ${editorCodigoBarrasActivo.id}`;
    input.value = String(celdaCodigo.textContent || producto.codigo_barras || '').trim();
    modal.style.display = 'flex';
    setTimeout(() => {
        input.focus({ preventScroll: true });
        input.select();
    }, 0);
}

async function guardarCodigoBarrasInventario() {
    const activo = editorCodigoBarrasActivo;
    const input = document.getElementById('inputEditarCodigo');
    const boton = document.getElementById('btnGuardarCodigo');
    if (!activo || !input || !boton) return;
    const codigo = input.value.trim();
    if (!codigo || /\s/.test(codigo) || codigo.length > 80) {
        mostrarMensajeInventario('El código debe tener hasta 80 caracteres y no llevar espacios.', 'error');
        input.focus();
        return;
    }

    boton.disabled = true;
    const textoOriginal = boton.textContent;
    boton.textContent = 'Guardando…';
    try {
        const datos = new FormData();
        datos.append('id', String(activo.id));
        datos.append('columna', 'codigo_barras');
        datos.append('valor', codigo);
        const respuesta = await fetch('actualizar_inventario.php', { method: 'POST', body: datos });
        const resultado = await leerRespuestaJson(respuesta);
        if (!respuesta.ok || !resultado || resultado.status !== 'ok') {
            throw new Error(resultado?.message || 'No se pudo guardar el código.');
        }

        activo.celdaCodigo.textContent = codigo;
        if (activo.fila) activo.fila.dataset.codigoBarras = codigo;
        window.productosInventario = (window.productosInventario || []).map(producto =>
            Number(producto.id) === activo.id ? { ...producto, codigo_barras: codigo } : producto
        );
        try {
            const cache = JSON.parse(localStorage.getItem(INVENTARIO_CACHE_KEY) || '{}');
            Object.keys(cache.queries || {}).forEach(consulta => {
                cache.queries[consulta].results = (cache.queries[consulta].results || []).map(producto =>
                    Number(producto.id) === activo.id ? { ...producto, codigo_barras: codigo } : producto
                );
            });
            localStorage.setItem(INVENTARIO_CACHE_KEY, JSON.stringify(cache));
        } catch (error) {}
        invalidarCatalogoPos();
        flashRow(activo.fila);
        mostrarMensajeInventario(`Código actualizado para “${activo.producto}”.`);
        cerrarEditorCodigoBarras();
    } catch (error) {
        mostrarMensajeInventario(error.message || 'No se pudo guardar el código.', 'error');
        input.focus();
    } finally {
        boton.disabled = false;
        boton.textContent = textoOriginal;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const inputCodigo = document.getElementById('inputEditarCodigo');
    const botonGuardar = document.getElementById('btnGuardarCodigo');
    if (inputCodigo) {
        inputCodigo.addEventListener('keydown', evento => {
            if (evento.key === 'Enter') {
                evento.preventDefault();
                guardarCodigoBarrasInventario();
            } else if (evento.key === 'Escape') {
                evento.preventDefault();
                cerrarEditorCodigoBarras();
            }
        });
    }
    if (botonGuardar) botonGuardar.addEventListener('click', guardarCodigoBarrasInventario);
});

function guardarEdicion(elemento, id, columna) {
    let valor = elemento.innerText.replace(/\s*\[(?:AGOTADO|SURTIR|REVISAR EXISTENCIAS)\]\s*$/, '').trim();
    if (columna === 'stock') {
        const anterior = Number(elemento.dataset.stockGuardado);
        let normalizado = valor.replace(',', '.');
        if (normalizado === '' || normalizado === '-' || /^-0(?:\.0+)?$/.test(normalizado)) {
            normalizado = '0';
        }
        if (!/^\d+(?:\.\d+)?$/.test(normalizado)) {
            if (Number(normalizado) !== anterior || anterior >= 0) {
                alert('Escribe una cantidad de existencias válida, igual o mayor a cero.');
            }
            updateStockVisual(elemento, anterior);
            return;
        }
        if (Number(normalizado) === anterior) {
            updateStockVisual(elemento, anterior);
            return;
        }
        valor = normalizado;
    }
    const formData = new FormData();
    formData.append('id', id);
    formData.append('columna', columna);
    formData.append('valor', valor);

    fetch('actualizar_inventario.php', { method: 'POST', body: formData })
        .then(async res => {
            const json = await leerRespuestaJson(res);
            if (json && json.status === 'ok') {
                // actualizar cache local si existe
                try {
                    const cacheRaw = localStorage.getItem(INVENTARIO_CACHE_KEY);
                    if (cacheRaw) {
                        const cache = JSON.parse(cacheRaw);
                        Object.keys(cache.queries || {}).forEach(q => {
                            cache.queries[q].results = cache.queries[q].results.map(r => r.id == id ? { ...r, [columna]: valor } : r);
                        });
                        localStorage.setItem(INVENTARIO_CACHE_KEY, JSON.stringify(cache));
                    }
                } catch (e) {}
                invalidarCatalogoPos();
                // Actualizar UI en tiempo real
                const fila = elemento.closest('tr');
                if (columna === 'stock') {
                    const n = parseFloat(valor.replace(',', '.')) || 0;
                    updateStockVisual(elemento, n);
                    actualizarAvisoStockNegativo();
                    if (mostrarSoloStockNegativo && n >= 0 && fila) fila.remove();
                }
                // efecto de brillo para indicar actualización
                flashRow(fila);
            } else {
                alert('Error al guardar: ' + (json && json.message ? json.message : JSON.stringify(json)));
                if (columna === 'stock') updateStockVisual(elemento, Number(elemento.dataset.stockGuardado));
            }
        }).catch(err => {
            console.error('Error al guardar edición:', err);
            alert('Error de conexión al guardar.');
            if (columna === 'stock') updateStockVisual(elemento, Number(elemento.dataset.stockGuardado));
        });
}

function toggleActivo(id, fila, btn) {
    const nuevo = btn.textContent === 'Pausar' ? '0' : '1';
    const formData = new FormData();
    formData.append('id', id);
    formData.append('columna', 'activo');
    formData.append('valor', nuevo);

    fetch('actualizar_inventario.php', { method: 'POST', body: formData })
        .then(async res => {
            const json = await leerRespuestaJson(res);
            if (json && json.status === 'ok') {
                if (nuevo === '0') {
                    fila.style.opacity = '0.5'; btn.textContent = 'Reactivar';
                    try { localStorage.setItem('nuvi_product_deactivated', JSON.stringify({ id: id, ts: Date.now() })); } catch (e) {}
                } else {
                    fila.style.opacity = ''; btn.textContent = 'Pausar';
                    try { localStorage.setItem('nuvi_product_reactivated', JSON.stringify({ id: id, ts: Date.now() })); } catch (e) {}
                }
                invalidarCatalogoPos();
                flashRow(fila);
            } else alert('Error al actualizar estado: ' + (json && json.message ? json.message : JSON.stringify(json)));
        }).catch(err => { alert('Error de conexión'); console.error(err); });
}

function eliminarProducto(id, fila) {
    if (!confirm('¿Desactivar este producto? Se marcará como inactivo y no aparecerá en el POS.')) return;
    const formData = new FormData();
    formData.append('id', id);
    formData.append('accion', 'eliminar');

    fetch('actualizar_inventario.php', { method: 'POST', body: formData })
            .then(async res => {
                const json = await leerRespuestaJson(res);
                if (json && json.status === 'ok') {
                    fila.style.opacity = '0.5';
                    const btnToggle = fila.querySelector('.btn-toggle'); if (btnToggle) btnToggle.textContent = 'Reactivar';
                    flashRow(fila);
                    try { localStorage.setItem('nuvi_product_deactivated', JSON.stringify({ id: id, ts: Date.now() })); } catch (e) {}
                    invalidarCatalogoPos();
                } else {
                    alert('Error al desactivar: ' + (json && json.message ? json.message : JSON.stringify(json)));
                }
            }).catch(err => { alert('Error de conexión'); console.error(err); });
}

function ordenarTablaPorCampo(campo) {
    const tbody = document.getElementById('cuerpoTabla');
    if (!tbody) return;
    const filas = Array.from(tbody.querySelectorAll('tr'));
    const currentDir = tbody.getAttribute('data-sort-dir') || 'asc';
    const dir = currentDir === 'asc' ? 'desc' : 'asc';

    filas.sort((a, b) => {
        const getCellText = (row, field) => {
            const idx = { id:0, codigo_barras:1, nombre:2, marca:3, precio_venta:4, precio_compra:5, stock:6, categoria:7 }[field];
            const cell = row.cells[idx];
            return cell ? cell.innerText.replace(/[^0-9.,-]/g, '').trim() : '';
        };
        const va = getCellText(a, campo);
        const vb = getCellText(b, campo);
        const na = parseFloat(va.replace(',', '.'));
        const nb = parseFloat(vb.replace(',', '.'));
        if (!isNaN(na) && !isNaN(nb)) return dir === 'asc' ? na - nb : nb - na;
        return dir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });

    const frag = document.createDocumentFragment(); filas.forEach(f => frag.appendChild(f));
    tbody.innerHTML = ''; tbody.appendChild(frag);
    tbody.setAttribute('data-sort-dir', dir);
}

// Nota: la función completa `renderizarTabla` está definida arriba (crea filas con edición y acciones).
