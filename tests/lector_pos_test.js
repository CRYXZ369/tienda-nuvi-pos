'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(process.argv[2] || require('node:path').join(__dirname, '../punto_de_venta.php'), 'utf8');
function fragmento(desde, hasta) {
    const inicio = source.indexOf(desde);
    const fin = source.indexOf(hasta, inicio);
    assert.ok(inicio >= 0 && fin > inicio, `No se encontró ${desde}`);
    return source.slice(inicio, fin);
}

function crearCaja() {
    let tiempo = 100;
    let siguiente = 0;
    const timers = new Map();
    const carrito = [];
    const errores = [];
    class Campo {
        constructor(valor = '') { this.value = valor; this.selectionStart = valor.length; this.listeners = {}; this.inputMode = 'none'; }
        addEventListener(tipo, fn) { this.listeners[tipo] = fn; }
        dispatchEvent(evento) { if (this.listeners[evento.type]) this.listeners[evento.type](evento); }
        focus() { contexto.document.activeElement = this; }
        blur() { contexto.document.activeElement = null; }
        setSelectionRange() {}
        closest(selector) { return selector.includes('#pago-con') && this.protegido; }
    }
    const input = new Campo();
    const pago = new Campo('25');
    pago.protegido = true;
    const productos = new Map(['1234567890123', '87654321', '444444444444', '44444444444444']
        .map(codigo => [codigo, { codigo_barras: codigo }]));
    const DOM = { inputUniversal: input, panelGranel: null, sugerenciasBusqueda: { style: {} } };
    const contexto = {
        DOM, PRODUCTOS_POR_CODIGO: productos, LONGITUDES_CODIGO_BARRAS: [8, 12, 13, 14],
        INTERVALO_MAXIMO_LECTOR_MS: 320, INTERVALO_MAXIMO_LECTOR_BLUETOOTH_MS: 320,
        ESPERA_AUTOPROCESAR_LECTOR_MS: 320,
        performance: { now: () => tiempo }, Element: Campo, HTMLInputElement: Campo, HTMLTextAreaElement: Campo,
        document: { contains: () => true, addEventListener() {} },
        window: { getComputedStyle: () => ({ display: 'none' }) },
        Event: class { constructor(type) { this.type = type; } },
        setTimeout: (fn, ms) => { const id = ++siguiente; timers.set(id, { fn, fecha: tiempo + ms }); return id; },
        clearTimeout: id => timers.delete(id),
        procesarProducto: producto => carrito.push(producto.codigo_barras),
        mostrarError: mensaje => errores.push(mensaje),
        buscarProducto: codigo => errores.push(`Búsqueda remota: ${codigo}`),
        buscarProductoLocalPorCodigo: codigo => productos.get(codigo) || null,
        ocultarSugerencias() {}, busquedaInteligente: () => [], renderizarSugerencias() {},
        nuviHayModalPosAbierto: () => false,
        esTextoCorto: texto => /^\d+$/.test(texto) && texto.length < 8,
        console
    };
    vm.createContext(contexto);
    vm.runInContext(`
        let timerBusquedaUniversal = null, capturaLectorFisicoGlobal = null, timerCapturaLectorFisico = null;
        let revisionToqueBuscador = 0;
        let lecturaAnterior = '', lecturaInicioMs = 0, lecturaUltimoTeclaMs = 0, lecturaRapidaActiva = false;
        ${fragmento('            function esCodigoBarras(', '            function esTextoCorto(')}
        ${fragmento('            function reiniciarRitmoLector(', '            function nuviHayModalPosAbierto(')}
        ${fragmento('            function nuviDestinoCapturaLector(', '            // El lector HID se comporta')}
        ${fragmento('            function actualizarRitmoLector(', '            function buscarProductoLocalPorCodigo(')}
        ${fragmento('            function procesarEntradaUniversal(', '            function renderizarSugerencias(')}
        this.capturar = nuviCapturarLectorFisicoGlobal;
    `, contexto);
    function avanzar(ms) {
        const fin = tiempo + ms;
        while (true) {
            const prox = [...timers.entries()].sort((a, b) => a[1].fecha - b[1].fecha)[0];
            if (!prox || prox[1].fecha > fin) break;
            tiempo = prox[1].fecha;
            timers.delete(prox[0]);
            prox[1].fn();
        }
        tiempo = fin;
    }
    function digitos(campo, texto, intervalo = 5) {
        for (const digito of texto) {
            const evento = { target: campo, key: digito, code: `Digit${digito}`, preventDefault() {}, stopPropagation() {} };
            contexto.capturar(evento);
            campo.value += digito;
            campo.dispatchEvent({ type: 'input', target: campo, data: digito, inputType: 'insertText' });
            avanzar(intervalo);
        }
    }
    function enter(campo) {
        const evento = { target: campo, key: 'Enter', preventDefault() { this.defaultPrevented = true; }, stopPropagation() {} };
        contexto.capturar(evento);
        if (!evento.defaultPrevented) campo.dispatchEvent({ ...evento, type: 'keydown' });
    }
    return { input, pago, carrito, errores, avanzar, digitos, enter };
}

const codigo = '1234567890123';
for (const campo of ['input', 'pago']) {
    for (const [sufijo, pausa] of [[false, 100], [false, 300], [false, 400], [true, 100]]) {
        const caja = crearCaja();
        caja.digitos(caja[campo], codigo);
        if (sufijo) caja.enter(caja[campo]);
        caja.avanzar(pausa);
        caja.digitos(caja[campo], codigo);
        if (sufijo) caja.enter(caja[campo]);
        caja.avanzar(400);
        assert.deepEqual(caja.carrito, [codigo, codigo], `${campo}: dos escaneos, sufijo=${sufijo}, pausa=${pausa}`);
        if (campo === 'pago') assert.equal(caja.pago.value, '25');
    }
}
{
    const caja = crearCaja();
    caja.digitos(caja.input, '87654321');
    caja.avanzar(100);
    caja.digitos(caja.input, codigo, 20);
    caja.avanzar(400);
    assert.deepEqual(caja.carrito, ['87654321', codigo], 'Códigos distintos y longitudes diferentes');
}
{
    const caja = crearCaja();
    caja.digitos(caja.input, codigo, 150);
    caja.avanzar(400);
    assert.deepEqual(caja.carrito, [codigo], 'Lector Bluetooth lento');
}
{
    const caja = crearCaja();
    for (let i = 0; i < 2; i++) {
        caja.input.value += codigo;
        caja.input.dispatchEvent({ type: 'input', target: caja.input, data: codigo, inputType: 'insertText' });
        caja.avanzar(100);
    }
    caja.avanzar(400);
    assert.deepEqual(caja.carrito, [codigo, codigo], 'Lecturas insertadas en bloques por Android');
}
{
    const caja = crearCaja();
    caja.digitos(caja.input, '44444444444444444444444444');
    caja.avanzar(400);
    assert.deepEqual(caja.carrito, [], 'No se adivina una separación ambigua');
    assert.ok(caja.errores.length);
    assert.equal(caja.input.value, '44444444444444444444444444');
}
{
    const caja = crearCaja();
    caja.input.focus();
    caja.input.dispatchEvent({ type: 'pointerdown', target: caja.input });
    assert.equal(caja.input.inputMode, 'text', 'Tocar el buscador debe permitir el teclado');
    caja.input.focus();
    caja.digitos(caja.input, codigo);
    caja.enter(caja.input);
    assert.equal(caja.input.inputMode, 'none', 'Escanear no debe abrir el teclado');
    assert.deepEqual(caja.carrito, [codigo]);
    caja.input.dispatchEvent({ type: 'pointerdown', target: caja.input });
    assert.equal(caja.input.inputMode, 'text', 'Se puede volver a escribir después de escanear');
}
console.log('OK: lecturas consecutivas con y sin sufijo, Bluetooth, cobro y ambigüedad.');
