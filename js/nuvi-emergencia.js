(() => {
    'use strict';

    const DB = 'nuvi_emergencia_v1';
    const CATALOGO = 'catalogo';
    const VENTAS = 'ventas';
    let apertura;

    function abrir() {
        if (!apertura) {
            apertura = new Promise((resolve, reject) => {
                const solicitud = indexedDB.open(DB, 1);
                solicitud.onupgradeneeded = () => {
                    solicitud.result.createObjectStore(CATALOGO, { keyPath: 'id' });
                    solicitud.result.createObjectStore(VENTAS, { keyPath: 'id' });
                };
                solicitud.onsuccess = () => resolve(solicitud.result);
                solicitud.onerror = () => reject(solicitud.error || new Error('No se pudo abrir la memoria de emergencia.'));
                solicitud.onblocked = () => reject(new Error('Otra pestaña impide abrir la memoria de emergencia.'));
            }).catch(error => {
                apertura = null;
                throw error;
            });
        }
        return apertura;
    }

    async function leer(tienda, clave) {
        const base = await abrir();
        return new Promise((resolve, reject) => {
            const tx = base.transaction(tienda, 'readonly');
            const solicitud = clave === undefined ? tx.objectStore(tienda).getAll() : tx.objectStore(tienda).get(clave);
            solicitud.onsuccess = () => resolve(solicitud.result);
            solicitud.onerror = () => reject(solicitud.error);
            tx.onabort = () => reject(tx.error || new Error('No se pudo leer la memoria de emergencia.'));
        });
    }

    async function escribir(tienda, valor, operacion = 'put') {
        const base = await abrir();
        return new Promise((resolve, reject) => {
            const tx = base.transaction(tienda, 'readwrite');
            tx.objectStore(tienda)[operacion](valor);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error || new Error('No se pudo guardar en la tablet.'));
            tx.onabort = () => reject(tx.error || new Error('No se pudo guardar en la tablet.'));
        });
    }

    function claveVenta() {
        if (!window.crypto || !window.crypto.getRandomValues) throw new Error('Esta tablet no puede crear identificadores seguros.');
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return 'emergencia_' + Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    }

    function esGranel(producto) {
        return ['kg', 'g', 'l', 'lt', 'ml'].includes(String(producto.unidad_medida || '').trim().toLowerCase());
    }

    function iconoGranel(producto) {
        const nombre = String(producto.nombre || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        if (nombre.includes('limon')) return '🍋';
        if (nombre.includes('jamon') && nombre.includes('pavo')) return '🦃';
        if (nombre.includes('jamon') && nombre.includes('pierna')) return '🐖';
        if (nombre.includes('jamon')) return '🍖';
        if (nombre.includes('queso')) return '🧀';
        if (nombre.includes('tomate') || nombre.includes('jitomate')) return '🍅';
        if (nombre.includes('cebolla')) return '🧅';
        if (nombre.includes('papa')) return '🥔';
        if (nombre.includes('naranja')) return '🍊';
        return '⚖️';
    }

    function normalizarGrupo(texto) {
        return String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function clasificarGranel(producto) {
        const categoria = normalizarGrupo(producto.categoria);
        const nombre = normalizarGrupo(producto.nombre);
        let grupo = String(producto.categoria || '').trim().replace(/\s+/g, ' ') || 'Otros';
        let icono = '📦';
        if (/carnes? frias?/.test(categoria)) { grupo = 'Carnes frías'; icono = '🍖'; }
        else if (/verdura/.test(categoria)) { grupo = 'Verduras'; icono = '🥬'; }
        else if (/^fruta/.test(categoria)) { grupo = 'Frutas'; icono = '🍎'; }
        else if (/lacteo|cremeria/.test(categoria)) { grupo = 'Lácteos'; icono = '🧀'; }
        else if (/detergente|jarceria|limpieza/.test(categoria)) { grupo = 'Jarciería y limpieza'; icono = '🧼'; }
        const familias = [
            [/jamon/, 'Jamón', '🍖'], [/salchicha/, 'Salchicha', '🌭'], [/tocino/, 'Tocino', '🥓'],
            [/longaniza/, 'Longaniza', '🌭'], [/queso/, 'Quesos', '🧀'],
            [/chile|chipotle/, 'Chiles', '🌶️'], [/manzana/, 'Manzanas', '🍎'],
            [/frijol/, 'Frijoles', '🫘'], [/naranja/, 'Naranjas', '🍊']
        ];
        const familia = familias.find(([patron]) => patron.test(nombre));
        return { grupo, icono, familia: familia ? familia[1] : 'Otros productos', iconoFamilia: familia ? familia[2] : '⚖️' };
    }

    function crearArbolGranel(contenedor, productos, seleccionar) {
        const grupos = new Map();
        productos.filter(esGranel).forEach(producto => {
            const datos = clasificarGranel(producto);
            const clave = normalizarGrupo(datos.grupo);
            if (!grupos.has(clave)) grupos.set(clave, { nombre: datos.grupo, icono: datos.icono, familias: new Map() });
            const familias = grupos.get(clave).familias;
            const claveFamilia = normalizarGrupo(datos.familia);
            if (!familias.has(claveFamilia)) familias.set(claveFamilia, { nombre: datos.familia, icono: datos.iconoFamilia, productos: [] });
            familias.get(claveFamilia).productos.push(producto);
        });
        const ordenar = lista => lista.sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));
        function boton(texto, accion) {
            const elemento = document.createElement('button');
            elemento.type = 'button';
            elemento.style.cssText = 'min-height:64px;padding:10px;font-size:16px;font-weight:700;cursor:pointer;';
            elemento.textContent = texto;
            elemento.addEventListener('click', accion);
            contenedor.append(elemento);
        }
        function inicio() {
            contenedor.replaceChildren();
            if (!grupos.size) contenedor.textContent = 'No hay productos a granel en el catálogo.';
            ordenar(Array.from(grupos.values())).forEach(grupo => boton(`${grupo.icono} ${grupo.nombre}`, () => familias(grupo)));
        }
        function familias(grupo) {
            contenedor.replaceChildren();
            boton('← Categorías', inicio);
            ordenar(Array.from(grupo.familias.values())).forEach(familia => boton(`${familia.icono} ${familia.nombre}`, () => lista(grupo, familia)));
        }
        function lista(grupo, familia) {
            contenedor.replaceChildren();
            boton(`← ${grupo.nombre}`, () => familias(grupo));
            ordenar(familia.productos.slice()).forEach(producto => {
                boton(`${iconoGranel(producto)} ${producto.nombre}${producto.marca ? ` · ${producto.marca}` : ''} · $${Number(producto.precio_venta).toFixed(2)}/${producto.unidad_medida} · ID ${producto.id}`, () => seleccionar(producto));
            });
        }
        inicio();
    }

    async function guardarCatalogo(idUsuario, nombre, productos) {
        if (!idUsuario || !Array.isArray(productos) || !productos.length) {
            throw new Error('No hay un catálogo válido para preparar la caja de emergencia.');
        }
        await escribir(CATALOGO, {
            id: 'actual', id_usuario: idUsuario, nombre, productos, actualizado: new Date().toISOString()
        });
        if (navigator.storage && navigator.storage.persist) {
            navigator.storage.persist().catch(error => console.warn('No se pudo solicitar almacenamiento persistente:', error));
        }
    }

    async function pendientes(idUsuario) {
        return (await leer(VENTAS)).filter(venta => Number(venta.id_usuario) === Number(idUsuario))
            .sort((a, b) => a.createdAt.localeCompare(b.createdAt));
    }

    async function otrasPendientes(idUsuario) {
        return (await leer(VENTAS)).filter(venta => Number(venta.id_usuario) !== Number(idUsuario)).length;
    }

    async function solicitud(url, opciones = {}) {
        const control = new AbortController();
        const timer = setTimeout(() => control.abort(), 5000);
        try {
            return await fetch(url, { ...opciones, credentials: 'same-origin', cache: 'no-store', signal: control.signal });
        } finally {
            clearTimeout(timer);
        }
    }

    async function sincronizar(idUsuario) {
        const cola = await pendientes(idUsuario);
        if (!cola.length) return { pendientes: 0, errores: [] };
        let sesion;
        try {
            const respuesta = await solicitud('sesion_keepalive.php', { headers: { Accept: 'application/json' } });
            if (!respuesta.ok) throw new Error('Inicia sesión con la misma cuenta para enviar las ventas.');
            sesion = await respuesta.json();
        } catch (error) {
            return { pendientes: cola.length, errores: ['Sin conexión o sesión no disponible. Las ventas siguen guardadas en esta tablet.'] };
        }
        if (Number(sesion.id_usuario) !== Number(idUsuario) || !sesion.csrf_token) {
            return { pendientes: cola.length, errores: ['Inicia sesión con la cuenta que registró estas ventas.'] };
        }
        const errores = [];
        for (const venta of cola) {
            try {
                const respuesta = await solicitud('finalizar_venta.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Nuvi-CSRF': sesion.csrf_token },
                    body: JSON.stringify({ ...venta.payload, id_usuario_origen: venta.id_usuario })
                });
                const resultado = await respuesta.json();
                if (!respuesta.ok || resultado.success !== true) {
                    throw new Error(resultado.message || resultado.error || `HTTP ${respuesta.status}`);
                }
                await escribir(VENTAS, venta.id, 'delete');
            } catch (error) {
                const motivo = (error.message || '').includes('El precio cambió')
                    ? 'El precio cambió y necesita revisión'
                    : (error.message || 'sin conexión').replace(/[.。]+$/, '');
                errores.push(`Venta ya cobrada ${new Date(venta.createdAt).toLocaleString('es-MX')}: ${motivo}. No vuelvas a cobrarla.`);
            }
        }
        return { pendientes: (await pendientes(idUsuario)).length, errores };
    }

    function iniciarEnCaja(idUsuario) {
        const preparacion = document.createElement('div');
        preparacion.setAttribute('role', 'status');
        preparacion.style.cssText = 'margin:12px auto;padding:10px;max-width:1050px;border-radius:10px;font-weight:700;';
        const panel = document.createElement('div');
        panel.setAttribute('role', 'status');
        panel.style.cssText = 'display:none;margin:12px auto;padding:12px;max-width:1050px;background:#fff7ed;color:#9a3412;border:2px solid #f59e0b;border-radius:10px;font-weight:700;';
        document.querySelector('.contenedor').prepend(preparacion, panel);
        let activo = false;
        const actualizar = async () => {
            if (activo) return;
            activo = true;
            try {
                const catalogo = await leer(CATALOGO, 'actual');
                const lista = window.isSecureContext && 'serviceWorker' in navigator && navigator.serviceWorker.controller
                    && 'caches' in window && await caches.match('offline.html')
                    && await caches.match('js/nuvi-emergencia.js') && await caches.match('js/caja-emergencia.js')
                    && catalogo && Number(catalogo.id_usuario) === Number(idUsuario) && catalogo.productos.length;
                preparacion.style.background = lista ? '#dcfce7' : '#fff7ed';
                preparacion.style.color = lista ? '#166534' : '#9a3412';
                preparacion.textContent = lista
                    ? 'Caja de emergencia preparada en esta tablet.'
                    : 'Caja de emergencia aún no preparada. Mantén esta página abierta con conexión y recárgala.';
                const resultado = await sincronizar(idUsuario);
                const otras = await otrasPendientes(idUsuario);
                panel.style.display = resultado.pendientes || otras ? 'block' : 'none';
                panel.textContent = (resultado.pendientes
                    ? `${resultado.pendientes} venta(s) de emergencia pendientes. ${resultado.errores.join(' ')} `
                    : '') + (otras ? `${otras} venta(s) de emergencia pertenecen a otra cuenta; inicia sesión con esa cuenta para enviarlas. ` : '')
                    + (resultado.pendientes || otras ? 'No borres los datos de esta tablet.' : '');
                if (resultado.pendientes) {
                    const enlace = document.createElement('a');
                    enlace.href = 'offline.html';
                    enlace.textContent = ' Ver detalle de ventas pendientes';
                    panel.append(enlace);
                }
            } catch (error) {
                preparacion.style.background = '#fff7ed';
                preparacion.style.color = '#9a3412';
                preparacion.textContent = 'No se pudo comprobar si la caja de emergencia está preparada.';
                panel.style.display = 'block';
                panel.textContent = 'No se pudo revisar la memoria de emergencia: ' + error.message;
                console.error('Caja de emergencia:', error);
            } finally {
                activo = false;
            }
        };
        actualizar();
        window.addEventListener('online', actualizar);
        window.addEventListener('focus', actualizar);
        if ('serviceWorker' in navigator) navigator.serviceWorker.addEventListener('controllerchange', actualizar);
        setInterval(actualizar, 30000);
    }

    window.NuviEmergencia = { guardarCatalogo, leerCatalogo: () => leer(CATALOGO, 'actual'), pendientes, otrasPendientes, escribirVenta: venta => escribir(VENTAS, venta, 'add'), claveVenta, sincronizar, iniciarEnCaja, esGranel, iconoGranel, crearArbolGranel };
})();
