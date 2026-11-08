(async () => {
    'use strict';
    const resultado = document.getElementById('resultado');
    const verificar = (condicion, mensaje) => { if (!condicion) throw new Error(mensaje); };
    const flush = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };

    try {
        const inventario = await (await fetch('../js/buscar_productos.js')).text();
        const inicio = inventario.indexOf('function guardarEdicion(');
        const fin = inventario.indexOf('\n}', inicio);
        verificar(inicio >= 0 && fin >= 0, 'No se encontró la edición de inventario.');
        const envios = [];
        const errores = [];
        class Datos {
            constructor() { this.data = {}; }
            append(clave, valor) { this.data[clave] = valor; }
        }
        const crearEdicion = new Function(
            'FormData', 'fetch', 'updateStockVisual', 'alert', 'localStorage',
            'invalidarCatalogoPos', 'actualizarAvisoStockNegativo', 'flashRow',
            'leerRespuestaJson', 'mostrarSoloStockNegativo', 'INVENTARIO_CACHE_KEY',
            inventario.slice(inicio, fin + 2) + '; return guardarEdicion;'
        );
        const guardar = crearEdicion(
            Datos, async (url, opciones) => { envios.push(opciones.body.data); return {}; },
            (elemento, numero) => { elemento.visual = numero; }, mensaje => errores.push(mensaje),
            { getItem: () => null }, () => {}, () => {}, () => {},
            async () => ({ status: 'ok' }), false, 'prueba'
        );
        for (const [valor, esperado] of [['', '0'], ['-', '0'], ['-0', '0'], ['-0,00', '0'], ['2,5', '2.5']]) {
            const elemento = { innerText: valor, dataset: { stockGuardado: '-1' }, closest: () => null };
            const anterior = envios.length;
            guardar(elemento, 1, 'stock');
            await flush();
            verificar(envios[anterior]?.valor === esperado, `Stock ${valor}: envío incorrecto.`);
            verificar(elemento.visual === Number(esperado), `Stock ${valor}: visualización incorrecta.`);
        }
        for (const valor of ['-2', 'abc']) {
            const elemento = { innerText: valor, dataset: { stockGuardado: '-1' }, closest: () => null };
            const anteriores = envios.length;
            const alertas = errores.length;
            guardar(elemento, 1, 'stock');
            verificar(envios.length === anteriores && errores.length === alertas + 1 && elemento.visual === -1,
                `Stock inválido ${valor}: debe rechazarse.`);
        }

        const codigo = await (await fetch('../js/nuvi-pwa.js')).text();
        const eventosVentana = {};
        const eventosDocumento = {};
        const timers = new Map();
        let siguienteTimer = 0;
        let modo = 'ok';
        let aviso;
        const elemento = () => ({
            hidden: false, style: { cssText: '', setProperty() {} },
            addEventListener() {}, setAttribute() {}, append(...hijos) { this.children = hijos; }
        });
        const documento = {
            hidden: false, querySelector: () => ({ href: location.origin + '/manifest.webmanifest' }),
            getElementById: () => null, createElement: elemento,
            body: { append: nodo => { aviso = nodo; } },
            addEventListener: (nombre, fn) => { eventosDocumento[nombre] = fn; }
        };
        const ventana = { isSecureContext: true, addEventListener: (nombre, fn) => { eventosVentana[nombre] = fn; } };
        const navegador = { serviceWorker: { register: async () => {}, controller: {} } };
        const peticion = (url, opciones) => {
            if (modo === 'ok') return Promise.resolve({ ok: true });
            if (modo === 'error') return Promise.reject(new Error('Sin conexión simulada'));
            return new Promise((resolve, reject) => opciones.signal.addEventListener('abort', () => reject(new Error('Cancelada'))));
        };
        const programar = (fn, ms) => { const id = ++siguienteTimer; timers.set(id, { fn, ms }); return id; };
        const cancelar = id => timers.delete(id);
        const avanzar = ms => {
            for (const [id, timer] of [...timers]) {
                if (timer.ms === ms) { timers.delete(id); timer.fn(); }
            }
        };
        new Function('window', 'document', 'navigator', 'fetch', 'caches', 'setTimeout',
            'clearTimeout', 'setInterval', 'URL', 'AbortController', 'console', codigo)(
            ventana, documento, navegador, peticion, { match: async () => true },
            programar, cancelar, () => {}, URL, AbortController, console
        );
        eventosVentana.load();
        await flush();
        verificar(aviso.hidden, 'No debe avisar con conexión.');
        modo = 'error';
        eventosVentana.offline();
        await flush();
        verificar(aviso.hidden, 'Un fallo transitorio no debe mostrar el aviso.');
        modo = 'ok';
        avanzar(3000);
        await flush();
        verificar(aviso.hidden, 'La recuperación debe conservar el aviso oculto.');
        modo = 'error';
        eventosVentana.offline();
        await flush();
        avanzar(3000);
        await flush();
        verificar(!aviso.hidden && !aviso.children[1].hidden, 'Dos fallos deben ofrecer la caja preparada.');
        modo = 'ok';
        eventosVentana.online();
        await flush();
        verificar(aviso.hidden, 'Al recuperar la conexión debe ocultarse el aviso.');
        modo = 'pendiente';
        eventosVentana.offline();
        await flush();
        documento.hidden = true;
        eventosDocumento.visibilitychange();
        documento.hidden = false;
        modo = 'ok';
        eventosDocumento.visibilitychange();
        await flush();
        verificar(aviso.hidden, 'Una petición suspendida no debe alertar al despertar.');
        resultado.textContent = 'OK';
    } catch (error) {
        resultado.textContent = 'ERROR: ' + error.message;
        console.error(error);
    }
})();
