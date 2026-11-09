'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(process.argv[2] || require('node:path').join(__dirname, '../abastecimiento.php'), 'utf8');
const inicio = source.indexOf('    const buscarCodigo = ');
const fin = source.indexOf("    document.getElementById('agregar-linea')", inicio);
assert.ok(inicio >= 0 && fin > inicio, 'No se encontró la lógica de lectura.');

function crearEntorno() {
    let tiempo = 0;
    let id = 0;
    const timers = new Map();
    const seleccionados = [];
    class Campo {
        constructor() { this.value = ''; this.listeners = {}; this.type = 'text'; this.name = ''; }
        addEventListener(tipo, fn) { (this.listeners[tipo] ||= []).push(fn); }
        removeEventListener(tipo, fn) { this.listeners[tipo] = (this.listeners[tipo] || []).filter(item => item !== fn); }
        dispatch(tipo, props = {}) {
            const evento = { type: tipo, target: this, currentTarget: this, ...props, preventDefault() { this.defaultPrevented = true; } };
            for (const fn of [...(this.listeners[tipo] || [])]) fn(evento);
            return evento;
        }
        focus() { activos.campo = this; }
        closest(selector) { return selector === 'form' ? this.form || null : null; }
    }
    const opciones = [
        { value: '1', dataset: { codigo: '12345678' }, textContent: 'Producto corto' },
        { value: '2', dataset: { codigo: '1234567890123' }, textContent: 'Producto largo' },
    ];
    const activos = {};
    const campos = {};
    for (const tipo of ['compra', 'merma', 'caducidad']) {
        campos[`codigo-${tipo}`] = new Campo();
        campos[`mensaje-codigo-${tipo}`] = { textContent: '' };
    }
    const formularios = {};
    for (const tipo of ['merma', 'caducidad']) {
        const selector = new Campo();
        selector.options = opciones;
        const cantidad = new Campo();
        cantidad.type = 'number';
        cantidad.name = 'cantidad';
        const fecha = tipo === 'caducidad' ? new Campo() : null;
        if (fecha) { fecha.type = 'date'; fecha.name = 'fecha_caducidad'; }
        formularios[tipo] = { selector, cantidad, fecha, closest() { return this; },
            querySelector(q) { return q.startsWith('select') ? selector : (q.includes('fecha_caducidad') ? fecha : cantidad); } };
        campos[`codigo-${tipo}`].form = formularios[tipo];
        cantidad.form = formularios[tipo];
        if (fecha) fecha.form = formularios[tipo];
    }
    const lineas = { children: [] };
    const nuevaLinea = () => {
        const selector = new Campo();
        const cantidad = new Campo();
        const linea = { selector, cantidad, querySelector(q) { return q === 'select' ? selector : cantidad; } };
        selector.dispatchEvent = () => seleccionados.push(selector.value);
        lineas.children.push(linea);
        return linea;
    };
    nuevaLinea();
    const listeners = {};
    const contexto = {
        document: { getElementById: nombre => campos[nombre] || (nombre.startsWith('form-') ? formularios[nombre.slice(5)] : null),
            addEventListener(tipo, fn) { listeners[tipo] = fn; }, body: new Campo() },
        plantilla: { content: { querySelector: () => ({ options: opciones }) } },
        lineas, agregar: nuevaLinea, filtrarMarcas() {},
        Element: Campo, HTMLInputElement: Campo, HTMLSelectElement: class {}, HTMLButtonElement: class {},
        performance: { now: () => tiempo },
        Event: class { constructor(type) { this.type = type; } },
        Date: { now: () => tiempo },
        setTimeout: (fn, ms) => { const key = ++id; timers.set(key, { fn, cuando: tiempo + ms }); return key; },
        clearTimeout: key => timers.delete(key),
    };
    vm.runInNewContext(source.slice(inicio, fin), contexto);
    function avanzar(ms) {
        const finTiempo = tiempo + ms;
        for (;;) {
            const siguiente = [...timers.entries()].sort((a, b) => a[1].cuando - b[1].cuando)[0];
            if (!siguiente || siguiente[1].cuando > finTiempo) break;
            tiempo = siguiente[1].cuando;
            timers.delete(siguiente[0]);
            siguiente[1].fn();
        }
        tiempo = finTiempo;
    }
    function escribir(campo, texto, intervalo = 20) {
        for (const caracter of texto) {
            campo.value += caracter;
            campo.dispatch('input');
            avanzar(intervalo);
        }
    }
    function teclaGlobal(destino, key) {
        const event = { target: destino, key, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {} };
        listeners.keydown(event);
        if (/^\d$/.test(key) && !event.defaultPrevented) destino.value += key;
        return event;
    }
    return { campos, formularios, lineas, activos, seleccionados, avanzar, escribir, teclaGlobal };
}

for (const tipo of ['compra', 'merma', 'caducidad']) {
    for (const sufijo of [null, 'Enter', 'Tab']) {
        const e = crearEntorno();
        const campo = e.campos[`codigo-${tipo}`];
        e.escribir(campo, '1234567890123', 150);
        if (sufijo) assert.equal(campo.dispatch('keydown', { key: sufijo }).defaultPrevented, true);
        e.avanzar(600);
        const selector = tipo === 'compra' ? e.lineas.children[0].selector : e.formularios[tipo].selector;
        const cantidad = tipo === 'compra' ? e.lineas.children[0].cantidad : e.formularios[tipo].cantidad;
        assert.equal(selector.value, '2', `${tipo}, sufijo ${sufijo}`);
        assert.equal(e.activos.campo, cantidad);
        assert.equal(campo.value, '');
        assert.match(e.campos[`mensaje-codigo-${tipo}`].textContent, /Producto seleccionado/);
        if (!sufijo) assert.equal(cantidad.dispatch('keydown', { key: 'Enter' }).defaultPrevented, true);
        if (tipo === 'compra') assert.equal(e.lineas.children.length, 1, 'No duplicar con temporizador y sufijo');
    }
}
{
    const e = crearEntorno();
    const campo = e.campos['codigo-compra'];
    e.escribir(campo, '12345678');
    e.avanzar(200);
    e.escribir(campo, '90123');
    e.avanzar(600);
    assert.equal(e.lineas.children[0].selector.value, '2', 'No seleccionar el prefijo durante una pausa Bluetooth');
    e.escribir(campo, '12345678');
    campo.dispatch('keydown', { key: 'Enter' });
    e.avanzar(600);
    assert.equal(e.lineas.children.length, 2, 'Cada escaneo de compra crea su línea');
}
for (const tipo of ['merma', 'caducidad']) {
    const e = crearEntorno();
    const campo = e.campos[`codigo-${tipo}`];
    e.formularios[tipo].selector.value = '2';
    e.formularios[tipo].cantidad.value = '5';
    e.escribir(campo, '99999');
    e.avanzar(600);
    assert.equal(e.formularios[tipo].selector.value, '');
    assert.equal(e.formularios[tipo].cantidad.value, '');
    assert.equal(campo.value, '99999');
    assert.match(e.campos[`mensaje-codigo-${tipo}`].textContent, /no encontrado/i);
}
{
    const e = crearEntorno();
    const campo = e.campos['codigo-merma'];
    e.escribir(campo, '12345678');
    campo.dispatch('blur');
    e.avanzar(600);
    assert.equal(e.formularios.merma.selector.value, '1', 'Al salir se procesa el código sin esperar el temporizador');
    assert.notEqual(e.activos.campo, e.formularios.merma.cantidad, 'El foco no vuelve al formulario al salir');
}
for (const sufijo of [null, 'Enter']) {
    const e = crearEntorno();
    const cantidad = e.formularios.caducidad.cantidad;
    cantidad.value = '2';
    for (const digito of '1234567890123') {
        e.teclaGlobal(cantidad, digito);
        e.avanzar(15);
    }
    if (sufijo) assert.equal(e.teclaGlobal(cantidad, sufijo).defaultPrevented, true);
    e.avanzar(650);
    assert.equal(e.formularios.caducidad.selector.value, '2', 'Escaneo desde cantidad selecciona producto');
    assert.equal(cantidad.value, '', 'Una nueva caducidad exige volver a capturar la cantidad');
    assert.match(e.campos['mensaje-codigo-caducidad'].textContent, /Producto seleccionado/);
}
{
    const e = crearEntorno();
    const cantidad = e.formularios.caducidad.cantidad;
    for (const digito of '12345678') {
        e.teclaGlobal(cantidad, digito);
        e.avanzar(150);
    }
    e.avanzar(650);
    assert.equal(e.formularios.caducidad.selector.value, '', 'No interpretar una cantidad escrita lentamente como lectura');
    assert.equal(cantidad.value, '12345678');
}
{
    const e = crearEntorno();
    const fecha = e.formularios.caducidad.fecha;
    fecha.value = '2026-12-01';
    for (const digito of '1234567890123') {
        e.teclaGlobal(fecha, digito);
        e.avanzar(15);
    }
    e.teclaGlobal(fecha, 'Enter');
    assert.equal(e.formularios.caducidad.selector.value, '2');
    assert.equal(fecha.value, '', 'Una fecha previa no debe aplicarse accidentalmente a otro producto');
}
console.log('OK: escaneo con y sin sufijo en compra, merma y caducidad.');
