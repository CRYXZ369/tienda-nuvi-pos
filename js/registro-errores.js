(() => {
    'use strict';
    const script = document.currentScript;
    const usuario = Number(script.dataset.usuario);
    const csrf = script.dataset.csrf;
    const base = new URL('../', script.src);
    const clave = `nuvi_reportes_error_${usuario}`;
    const originalFetch = window.fetch.bind(window);
    const objetivos = new Set(['finalizar_venta.php', 'registrar_venta_fiada.php', 'actualizar_inventario.php']);
    let enviando = false;
    let ultimo = null;
    let avisos = 0;
    const recientes = new Map();

    function ruta(valor) {
        try {
            const url = new URL(valor, location.href);
            if (url.origin !== location.origin || !url.pathname.startsWith(base.pathname)) return 'externo';
            const nombre = url.pathname.slice(base.pathname.length);
            return /^[a-zA-Z0-9_./-]{1,160}$/.test(nombre) ? nombre : 'desconocido';
        } catch (error) {
            return 'desconocido';
        }
    }

    function leer() {
        const datos = JSON.parse(localStorage.getItem(clave) || '[]');
        if (!Array.isArray(datos)) throw new Error('Cola de reportes inválida');
        return datos;
    }

    function almacenar(evento) {
        try {
            const cola = leer();
            if (cola.length >= 50) throw new Error('Cola de reportes llena');
            cola.push(evento);
            localStorage.setItem(clave, JSON.stringify(cola));
            enviar();
            return true;
        } catch (error) {
            console.error('No se pudo conservar el reporte de error:', error);
            return false;
        }
    }

    async function enviar() {
        if (enviando) return;
        enviando = true;
        try {
            while (true) {
                const cola = leer();
                if (!cola.length) break;
                const evento = cola[0];
                const respuesta = await originalFetch(new URL('registrar_error.php', base), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Nuvi-CSRF': csrf },
                    body: JSON.stringify(evento)
                });
                if (!respuesta.ok) {
                    console.warn('No se pudo enviar el reporte de error. HTTP', respuesta.status);
                    break;
                }
                const confirmacion = await respuesta.json();
                if (!confirmacion || confirmacion.success !== true) {
                    console.warn('La bitácora no confirmó el registro del error.');
                    break;
                }
                const actual = leer();
                const indice = actual.findIndex(item => item.id === evento.id && item.tipo === evento.tipo);
                if (indice !== -1) {
                    actual.splice(indice, 1);
                    localStorage.setItem(clave, JSON.stringify(actual));
                }
            }
        } catch (error) {
            console.warn('El reporte de error sigue pendiente:', error);
        } finally {
            enviando = false;
        }
    }

    function mostrar(evento, guardado) {
        const crear = () => {
            let panel = document.getElementById('nuvi-reporte-error');
            if (!panel) {
                panel = document.createElement('section');
                panel.id = 'nuvi-reporte-error';
                panel.setAttribute('role', 'status');
                panel.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:2147483646;max-width:340px;padding:14px;border:2px solid #92400e;border-radius:12px;box-shadow:0 3px 12px #0006;font:16px sans-serif';
                panel.style.setProperty('background', '#fffbeb', 'important');
                panel.style.setProperty('color', '#3d2c00', 'important');
                const texto = document.createElement('p');
                texto.id = 'nuvi-reporte-texto';
                texto.style.setProperty('color', '#3d2c00', 'important');
                const detalle = document.createElement('details');
                const titulo = document.createElement('summary');
                titulo.textContent = 'Contar qué pasó (opcional)';
                const ayuda = document.createElement('p');
                ayuda.textContent = 'No escribas contraseñas, datos de tarjetas ni datos personales.';
                ayuda.style.setProperty('color', '#3d2c00', 'important');
                const campo = document.createElement('textarea');
                campo.maxLength = 500;
                campo.rows = 3;
                campo.placeholder = '¿Qué estabas haciendo?';
                campo.setAttribute('aria-label', 'Qué pasó');
                campo.style.cssText = 'box-sizing:border-box;width:100%;font:inherit';
                const boton = document.createElement('button');
                boton.type = 'button';
                boton.textContent = 'Guardar explicación';
                const cerrar = document.createElement('button');
                cerrar.type = 'button';
                cerrar.textContent = 'Cerrar';
                [boton, cerrar].forEach(elemento => {
                    elemento.style.cssText = 'padding:9px;margin:6px 6px 0 0;border:1px solid #684400;border-radius:6px;font:inherit;cursor:pointer';
                    elemento.style.setProperty('background', '#684400', 'important');
                    elemento.style.setProperty('color', '#ffffff', 'important');
                });
                boton.addEventListener('click', () => {
                    if (!campo.value.trim() || !ultimo) return;
                    const comentario = { ...ultimo, tipo: 'comentario', comentario: campo.value.trim() };
                    if (almacenar(comentario)) {
                        campo.value = '';
                        detalle.open = false;
                        texto.textContent = 'Explicación guardada en este dispositivo para enviarla a la bitácora.';
                    } else {
                        texto.textContent = 'No se pudo guardar la explicación. Avise a administración.';
                    }
                });
                cerrar.addEventListener('click', () => { panel.hidden = true; });
                detalle.append(titulo, ayuda, campo, boton);
                panel.append(texto, detalle, cerrar);
                document.body.append(panel);
            }
            if (panel.querySelector('details').open && !panel.hidden) return;
            ultimo = guardado ? evento : null;
            panel.querySelector('details').hidden = !guardado;
            panel.querySelector('#nuvi-reporte-texto').textContent = guardado
                ? 'Se registró el problema para revisión. Puedes explicar qué pasó.'
                : 'No se pudo guardar el reporte. Avise a administración.';
            panel.hidden = false;
        };
        if (document.body) crear();
        else document.addEventListener('DOMContentLoaded', crear, { once: true });
    }

    function registrar(motivo, origen, datos = {}, mostrarAviso = false) {
        if (avisos >= 20) return;
        const firma = `${motivo}:${origen}:${datos.http || 0}:${datos.linea || 0}:${datos.codigo || ''}`;
        const ahora = Date.now();
        if (ahora - (recientes.get(firma) || 0) < 30000) return;
        recientes.set(firma, ahora);
        avisos++;
        const bytes = new Uint8Array(16);
        if (!window.crypto || !window.crypto.getRandomValues) {
            console.error('No se pudo generar una referencia para el reporte.');
            if (mostrarAviso) mostrar(null, false);
            return;
        }
        crypto.getRandomValues(bytes);
        const id = Array.from(bytes, valor => valor.toString(16).padStart(2, '0')).join('');
        const evento = {
            id, tipo: 'error', usuario, motivo, pagina: ruta(location.href), origen,
            fecha: new Date().toISOString(), ...datos
        };
        const guardado = almacenar(evento);
        if (mostrarAviso || !guardado) mostrar(evento, guardado);
    }

    window.addEventListener('error', event => {
        if (event.target && event.target !== window) {
            registrar('recurso', ruta(event.target.src || event.target.href || ''), {}, false);
        } else {
            registrar('javascript', ruta(event.filename || ''), { linea: event.lineno || 0, columna: event.colno || 0 }, false);
        }
    }, true);
    window.addEventListener('unhandledrejection', () => registrar('promesa', 'desconocido'));

    window.fetch = function (input, opciones) {
        const nombre = ruta(typeof input === 'string' || input instanceof URL ? input : input.url);
        if (!objetivos.has(nombre)) return originalFetch(input, opciones);
        const contexto = {};
        if (nombre === 'actualizar_inventario.php' && opciones && opciones.body instanceof FormData) {
            contexto.producto_id = Number(opciones.body.get('id')) || 0;
            contexto.campo = String(opciones.body.get('columna') || '');
        }
        return originalFetch(input, opciones).then(respuesta => {
            respuesta.clone().text().then(texto => {
                let json;
                try { json = JSON.parse(texto); } catch (error) { json = null; }
                const fallo = !respuesta.ok || (!json && texto.trim() !== 'ok') || (json && (json.error || json.success === false || json.status === 'error'));
                if (fallo) {
                    const mensaje = String(json && (json.error || json.message) || '');
                    const categorias = [
                        ['CSRF_INVALID', /^CSRF_INVALID$/], ['SESSION_EXPIRED', /^SESSION_EXPIRED$/],
                        ['PRECIO_INVALIDO', /^El precio debe ser válido\.$/],
                        ['STOCK_INVALIDO', /^El stock no puede ser negativo\.$/],
                        ['PRODUCTO_NO_ENCONTRADO', /^No se encontró el producto\.$/],
                        ['CAMPO_INVALIDO', /^Campo no permitido\.$/],
                        ['CODIGO_REPETIDO', /^Ese código ya pertenece a /],
                        ['PRECIO_CAMBIO', /^El precio cambió/],
                        ['STOCK_CAMBIO', /^(Stock insuficiente|El stock cambió)/],
                        ['VENTA_ERROR_INTERNO', /^Error procesando la venta$/]
                    ];
                    const codigo = (categorias.find(([, patron]) => patron.test(mensaje)) || [''])[0];
                    registrar(respuesta.ok ? 'respuesta' : 'http', nombre, { ...contexto, http: respuesta.status, codigo }, true);
                }
            }).catch(error => console.warn('No se pudo revisar la respuesta para diagnóstico:', error));
            return respuesta;
        }, error => {
            registrar('conexion', nombre, contexto, true);
            throw error;
        });
    };
    window.addEventListener('online', enviar);
    setInterval(enviar, 60000);
    enviar();
})();
