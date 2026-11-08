(() => {
    'use strict';
    const arbol = document.getElementById('arbol');
    const resultado = document.getElementById('resultado');
    const filas = [
        ['Nuez', 'Fruto'], ['Almendra', 'Frutos'], ['Arándano', 'Frutos'],
        ['Cloro', 'Detergentes'], ['Pierna ahumada', 'Carnes frias'],
        ['Jamon pavo', 'Carnes Frias'], ['Jamon pierna', 'Carnes frias'],
        ['Tocino', 'Carnes frias'], ['Longaniza', 'Carnes frias'],
        ['Queso puerco', 'Carnes frias'], ['Arroz', 'Abarrotes'],
        ['Botana', 'Botanas'], ['Morita', 'Chiles secos'], ['Dulce', 'Dulces agranel'],
        ['Pan', 'Pan de dulce'], ['Mole', 'Salsas'], ['Frijol', 'Semillas'],
        ['Producto en piezas', 'Frutos'], ['Pistache', 'Frutos'],
        ['Cacahuate', 'Frutos'], ['Semilla', 'Frutos']
    ].map(([nombre, categoria], indice) => ({
        id: indice + 1, nombre, categoria, unidad_medida: nombre === 'Producto en piezas' ? 'pza' : 'kg', precio_venta: 10
    }));
    let seleccionado;
    const botones = () => Array.from(arbol.querySelectorAll('button'));
    const buscar = texto => botones().find(boton => boton.textContent.includes(texto));
    const verificar = (condicion, mensaje) => { if (!condicion) throw new Error(mensaje); };
    const pulsar = texto => {
        const boton = buscar(texto);
        verificar(boton, `No se encontró ${texto}`);
        boton.click();
    };
    const scoresOriginales = localStorage.getItem('nuvi_pos_scores');
    try {
        localStorage.setItem('nuvi_pos_scores', JSON.stringify({ 1: 5, 6: 8, 7: 3 }));
        NuviEmergencia.crearArbolGranel(arbol, filas, producto => { seleccionado = producto.nombre; });
        ['🛒 Abarrotes', '🍿 Botanas', '🌶️ Chiles secos', '🍬 Dulces agranel',
            '🥜 Frutos', '🍩 Pan de dulce', '🥫 Salsas', '🌾 Semillas'].forEach(texto => verificar(buscar(texto), `Falta icono ${texto}`));
        verificar(botones().filter(boton => boton.textContent.includes('Frutos')).length === 1, 'Fruto y Frutos deben compartir una categoría');
        pulsar('Frutos');
        verificar(buscar('Nuez') && buscar('Almendra') && buscar('Arándano'), 'Los frutos deben mostrarse directamente');
        verificar(buscar('Pistache') && buscar('Cacahuate') && buscar('Semilla') && !buscar('Otros productos'), 'Todos los frutos deben mostrarse sin pasos adicionales');
        verificar(botones()[1].textContent.includes('Nuez'), 'El producto más vendido debe mostrarse primero');
        verificar(!buscar('Producto en piezas'), 'Los productos por pieza no deben aparecer');
        pulsar('Nuez');
        verificar(seleccionado === 'Nuez', 'Debe seleccionarse la nuez');
        pulsar('Categorías');
        pulsar('Jarciería');
        verificar(buscar('Cloro') && !buscar('Otros productos'), 'El cloro debe mostrarse directamente');
        pulsar('Categorías');
        pulsar('Carnes frías');
        verificar(buscar('Pierna ahumada') && !buscar('Otros productos'), 'La pierna debe mostrarse directamente');
        pulsar('Jamón');
        verificar(buscar('Jamon pavo') && buscar('Jamon pierna'), 'Los jamones deben conservar su familia');
        verificar(botones()[1].textContent.includes('Jamon pavo'), 'La familia debe ordenar los productos por puntuación');
        NuviEmergencia.incrementarOrdenGranel([{ id: 7, unidad_medida: 'kg', cantidad: 10 }]);
        NuviEmergencia.crearArbolGranel(arbol, filas, producto => { seleccionado = producto.nombre; });
        pulsar('Carnes frías');
        pulsar('Jamón');
        verificar(botones()[1].textContent.includes('Jamon pierna'), 'El orden debe actualizarse tras registrar una venta');
        resultado.textContent = 'OK';
    } catch (error) {
        resultado.textContent = 'ERROR: ' + error.message;
        throw error;
    } finally {
        if (scoresOriginales === null) localStorage.removeItem('nuvi_pos_scores');
        else localStorage.setItem('nuvi_pos_scores', scoresOriginales);
    }
})();
