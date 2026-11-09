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
        constructor() { this.value = ''; this.listeners = {}; }
        addEventListener(tipo, fn) { (this.listeners[tipo] ||= []).push(fn); }
        removeEventListener(tipo, fn) { this.listeners[tipo] = (this.listeners[tipo] || []).filter(item => item !== fn); }
        dispatch(tipo, props = {}) {
            const evento = { type: tipo, target: this, currentTarget: this, ...props, preventDefault() { this.defaultPrevented = true; } };
            for (const fn of [...(this.listeners[tipo] || [])]) fn(evento);
            return evento;
        }
        focus() { activos.campo = this; }
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
        formularios[tipo] = { selector, cantidad, querySelector(q) { return q.startsWith('select') ? selector : cantidad; } };
        campos[`codigo-${tipo}`].form = formularios[tipo];
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
    const contexto = {
        document: { getElementById: nombre => campos[nombre] },
        plantilla: { content: { querySelector: () => ({ options: opciones }) } },
        lineas, agregar: nuevaLinea, filtrarMarcas() {},
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
    return { campos, formularios, lineas, activos, seleccionados, avanzar, escribir };
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
    assert.equal(e.formularios.merma.selector.value, '', 'No cambiar una selección tras abandonar el buscador');
}
console.log('OK: escaneo con y sin sufijo en compra, merma y caducidad.');
