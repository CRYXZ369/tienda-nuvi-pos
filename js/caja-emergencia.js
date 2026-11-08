(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const buscar = $('buscar');
    const recibido = $('recibido');
    const carrito = new Map();
    let catalogo = null;
    let guardando = false;
    let sincronizando = false;
    let totalCentavos = 0;
    let productoGranel = null;
    let terminales = [];

    const dinero = centavos => '$' + (centavos / 100).toFixed(2);
    const avisar = texto => { $('mensaje').textContent = texto; };
    const normalizar = texto => String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

    function subtotalLinea(linea) {
        if (linea.importeCentavos !== undefined) return linea.importeCentavos;
        const exacto = Math.round((Number(linea.producto.precio_venta) * linea.cantidad + Number.EPSILON) * 100);
        if (linea.redondeo === 'abajo') return Math.floor(exacto / 100) * 100;
        if (linea.redondeo === 'arriba') return Math.ceil(exacto / 100) * 100;
        return exacto;
    }

    function terminalActual() {
        return terminales.find(terminal => String(terminal.id) === $('terminal').value);
    }

    function importeTarjeta(terminal) {
        if (!terminal || totalCentavos <= 0) return null;
        if (['comision_porcentaje', 'iva_porcentaje', 'cargo_fijo'].some(campo =>
            terminal[campo] === null || terminal[campo] === undefined || String(terminal[campo]).trim() === ''
            || !Number.isFinite(Number(terminal[campo])) || Number(terminal[campo]) < 0)) return null;
        const tasa = Number(terminal.comision_porcentaje) / 100 * (1 + Number(terminal.iva_porcentaje) / 100);
        const fijo = Number(terminal.cargo_fijo);
        if (!Number.isFinite(tasa) || !Number.isFinite(fijo) || tasa < 0 || tasa >= 1 || fijo < 0) return null;
        return Math.ceil(((totalCentavos / 100 + fijo) / (1 - tasa) * 100) - 0.000001);
    }

    function renderizar() {
        const lista = $('carrito');
        lista.replaceChildren();
        totalCentavos = 0;
        carrito.forEach((linea, id) => {
            const subtotal = subtotalLinea(linea);
            totalCentavos += subtotal;
            const fila = document.createElement('div');
            fila.className = 'fila';
            const texto = document.createElement('span');
            const cantidad = linea.importeCentavos !== undefined ? `≈ ${linea.cantidad.toFixed(3)}` : linea.cantidad;
            texto.textContent = `${linea.producto.nombre} · ${cantidad} ${linea.producto.unidad_medida || 'pza'} × ${dinero(Math.round(Number(linea.producto.precio_venta) * 100))} = ${dinero(subtotal)}`;
            const menos = document.createElement('button');
            menos.type = 'button';
            menos.textContent = linea.granel ? 'Quitar' : '−';
            menos.setAttribute('aria-label', linea.granel ? `Quitar ${linea.producto.nombre}` : `Quitar una unidad de ${linea.producto.nombre}`);
            menos.addEventListener('click', () => {
                if (guardando) return;
                if (linea.granel || --linea.cantidad === 0) carrito.delete(id);
                renderizar();
            });
            const mas = document.createElement('button');
            mas.type = 'button';
            mas.textContent = '+';
            mas.setAttribute('aria-label', `Agregar una unidad de ${linea.producto.nombre}`);
            mas.addEventListener('click', () => { if (!guardando) { linea.cantidad++; renderizar(); } });
            fila.append(texto, menos);
            if (!linea.granel) fila.append(mas);
            lista.append(fila);
        });
        $('total').textContent = dinero(totalCentavos);
        const pago = Number(recibido.value);
        const cambio = Math.round(pago * 100) - totalCentavos;
        $('cambio').textContent = cambio < 0 ? `Faltan ${dinero(-cambio)}` : `Cambio: ${dinero(cambio)}`;
        const metodo = $('metodo').value;
        $('efectivo-grupo').hidden = metodo !== 'efectivo';
        $('tarjeta-grupo').hidden = metodo !== 'tarjeta';
        const importe = importeTarjeta(terminalActual());
        $('tarjeta-importe').textContent = totalCentavos <= 0
            ? 'Agrega productos para calcular el importe de la terminal.'
            : importe === null
            ? 'No hay una configuración válida de terminal guardada. No cobres con tarjeta en esta caja.'
            : `Cobrar en la terminal: ${dinero(importe)} (incluye comisión).`;
        $('cobrar').disabled = !catalogo || !carrito.size || guardando;
    }

    function agregar(producto) {
        if (guardando) return;
        if (!Number.isFinite(Number(producto.precio_venta)) || Number(producto.precio_venta) <= 0) {
            avisar('Este producto no tiene un precio válido y no puede agregarse.');
            return;
        }
        if (NuviEmergencia.esGranel(producto)) {
            productoGranel = producto;
            $('granel-seleccion').textContent = `${NuviEmergencia.iconoGranel(producto)} ${producto.nombre} · ${dinero(Math.round(Number(producto.precio_venta) * 100))}/${producto.unidad_medida}`;
            $('granel-tipo').value = 'importe';
            actualizarTipoGranel();
            $('granel-cantidad').value = '';
            $('granel-redondeo').value = 'exacto';
            $('granel-formulario').hidden = false;
            $('granel-cantidad').focus();
            return;
        }
        const id = String(producto.id);
        const anterior = carrito.get(id);
        if (anterior) anterior.cantidad++;
        else carrito.set(id, { producto, cantidad: 1 });
        buscar.value = '';
        $('resultados').replaceChildren();
        avisar(`${producto.nombre} agregado.`);
        renderizar();
        buscar.focus();
    }

    function mostrarGranel() {
        NuviEmergencia.crearArbolGranel($('granel-rapido'), catalogo.productos, agregar);
    }

    function actualizarTipoGranel() {
        const peso = $('granel-tipo').value === 'peso';
        $('granel-etiqueta').textContent = peso ? `Cantidad en ${productoGranel.unidad_medida}, como indica la báscula` : 'Importe a vender ($)';
        $('granel-redondeo-grupo').hidden = !peso;
        $('granel-cantidad').value = '';
    }
    $('granel-tipo').addEventListener('change', actualizarTipoGranel);
    $('granel-agregar').addEventListener('click', () => {
        if (guardando || !productoGranel) return;
        const valor = $('granel-cantidad').value;
        const peso = $('granel-tipo').value === 'peso';
        const numero = Number(valor);
        const valido = peso ? /^\d+(?:\.\d{1,3})?$/ : /^\d+(?:\.\d{1,2})?$/;
        if (!valido.test(valor) || !Number.isFinite(numero) || numero <= 0) {
            avisar(peso ? 'Escribe una cantidad válida, con hasta tres decimales.' : 'Escribe un importe válido, con hasta dos decimales.');
            return;
        }
        const cantidad = peso ? numero : numero / Number(productoGranel.precio_venta);
        if (cantidad < 0.0005) {
            avisar('La cantidad calculada es demasiado pequeña para registrarse en el inventario.');
            return;
        }
        const id = NuviEmergencia.claveVenta();
        const linea = { producto: productoGranel, cantidad, granel: true, redondeo: peso ? $('granel-redondeo').value : 'exacto',
            ...(peso ? {} : { importeCentavos: Math.round(numero * 100) }) };
        if (subtotalLinea(linea) <= 0) {
            avisar('El importe de la pesada debe ser mayor a cero.');
            return;
        }
        carrito.set(id, linea);
        $('granel-formulario').hidden = true;
        avisar(`${productoGranel.nombre} agregado.`);
        productoGranel = null;
        renderizar();
    });
    $('granel-cancelar').addEventListener('click', () => { productoGranel = null; $('granel-formulario').hidden = true; });
    document.querySelectorAll('[data-peso]').forEach(boton => boton.addEventListener('click', () => {
        if (guardando) return;
        const campo = $('granel-cantidad');
        const tecla = boton.dataset.peso;
        let valor = campo.value;
        if (tecla === 'limpiar') valor = '';
        else if (tecla === 'borrar') valor = valor.slice(0, -1);
        else if (tecla === '.' && !valor.includes('.')) valor = (valor || '0') + '.';
        else if (/^\d$/.test(tecla) && valor.length < 13 && (!valor.includes('.') || valor.split('.')[1].length < ($('granel-tipo').value === 'peso' ? 3 : 2))) valor += tecla;
        campo.value = valor;
    }));
    $('metodo').addEventListener('change', renderizar);
    $('terminal').addEventListener('change', renderizar);

    function resultados() {
        const contenedor = $('resultados');
        contenedor.replaceChildren();
        const termino = normalizar(buscar.value);
        if (!catalogo || termino.length < 2) return [];
        const palabras = termino.split(/\s+/);
        const encontrados = catalogo.productos.filter(producto => {
            const texto = normalizar(`${producto.nombre} ${producto.marca || ''} ${producto.codigo_barras || ''}`);
            return palabras.every(palabra => texto.includes(palabra));
        }).slice(0, 10);
        encontrados.forEach(producto => {
            const boton = document.createElement('button');
            boton.className = 'producto';
            boton.type = 'button';
            boton.textContent = `${producto.nombre} · ${dinero(Math.round(Number(producto.precio_venta) * 100))}`;
            boton.addEventListener('click', () => agregar(producto));
            contenedor.append(boton);
        });
        return encontrados;
    }

    buscar.addEventListener('input', resultados);
    buscar.addEventListener('keydown', evento => {
        if (evento.key !== 'Enter') return;
        evento.preventDefault();
        const codigo = buscar.value.trim();
        const producto = catalogo && catalogo.productos.find(item => String(item.codigo_barras) === codigo);
        if (producto) agregar(producto);
        else avisar('No se encontró ese código. Busca el producto por nombre.');
    });
    let lectura = '';
    let ultimaTecla = 0;
    document.addEventListener('keydown', evento => {
        if (evento.target === buscar || (evento.target instanceof HTMLInputElement && !evento.target.readOnly)
            || evento.ctrlKey || evento.altKey || evento.metaKey || evento.isComposing) return;
        const ahora = performance.now();
        if (/^\d$/.test(evento.key)) {
            lectura = ahora - ultimaTecla <= 260 ? lectura + evento.key : evento.key;
            ultimaTecla = ahora;
            if (lectura.length > 32) lectura = '';
            return;
        }
        if (evento.key === 'Enter' && ahora - ultimaTecla <= 260 && lectura.length >= 8 && catalogo) {
            const producto = catalogo.productos.find(item => String(item.codigo_barras) === lectura);
            evento.preventDefault();
            evento.stopPropagation();
            if (producto) agregar(producto);
            else avisar('No se encontró ese código. Busca el producto por nombre.');
        }
        lectura = '';
    }, true);
    recibido.addEventListener('input', renderizar);
    document.querySelectorAll('[data-tecla]').forEach(boton => boton.addEventListener('click', () => {
        if (guardando) return;
        const tecla = boton.dataset.tecla;
        let valor = recibido.value;
        if (tecla === 'limpiar') valor = '';
        else if (tecla === 'borrar') valor = valor.slice(0, -1);
        else if (tecla === '.' && !valor.includes('.')) valor = (valor || '0') + '.';
        else if (/^\d$/.test(tecla) && valor.length < 12 && (!valor.includes('.') || valor.split('.')[1].length < 2)) valor += tecla;
        recibido.value = valor;
        renderizar();
    }));

    async function mostrarPendientes() {
        const cola = await NuviEmergencia.todasPendientes();
        const otras = cola.filter(venta => Number(venta.id_usuario) !== Number(catalogo.id_usuario)).length;
        const propias = cola.length - otras;
        $('pendientes').textContent = (propias
            ? `${propias} venta(s) pendientes de esta cuenta en esta tablet.`
            : 'No hay ventas de emergencia pendientes para esta cuenta.')
            + (otras ? ` ${otras} venta(s) más pertenecen a otra cuenta.` : '');
        const detalle = $('detalle-pendientes');
        detalle.replaceChildren();
        cola.forEach(venta => {
            const tarjeta = document.createElement('details');
            const titulo = document.createElement('summary');
            titulo.textContent = `${new Date(venta.createdAt).toLocaleString('es-MX')} · ${dinero(Math.round(venta.payload.total * 100))} · Cuenta ${venta.id_usuario}`;
            tarjeta.append(titulo);
            venta.payload.productos.forEach(producto => {
                const linea = document.createElement('p');
                const importeBase = Math.round((Number(producto.precio) * Number(producto.cantidad) + Number.EPSILON) * 100);
                const importe = producto.redondeo_granel === 'arriba' ? Math.ceil(importeBase / 100) * 100
                    : producto.redondeo_granel === 'abajo' ? Math.floor(importeBase / 100) * 100 : importeBase;
                linea.textContent = `${producto.nombre} · ${producto.cantidad} × ${dinero(Math.round(producto.precio * 100))} = ${dinero(importe)}${producto.origen_granel ? ` · redondeo ${producto.redondeo_granel}` : ''}`;
                tarjeta.append(linea);
            });
            const pago = document.createElement('p');
            pago.textContent = venta.payload.metodo_pago === 'efectivo'
                ? `Efectivo recibido: ${dinero(Math.round(venta.payload.pago_con_efectivo * 100))}`
                : venta.payload.metodo_pago === 'tarjeta'
                    ? `Tarjeta · ${(venta.payload.terminal_emergencia || {}).nombre || 'terminal'} · cobrado ${dinero(Math.round(venta.payload.cobro_tarjeta_emergencia * 100))}`
                    : 'Transferencia';
            tarjeta.append(pago);
            if (venta.payload.metodo_pago === 'tarjeta' && venta.payload.terminal_emergencia) {
                const terminal = venta.payload.terminal_emergencia;
                const comision = document.createElement('p');
                comision.textContent = `Comisión: ${terminal.comision_porcentaje}% · IVA: ${terminal.iva_porcentaje}% · Cargo fijo: $${Number(terminal.cargo_fijo).toFixed(2)}`;
                tarjeta.append(comision);
            }
            const revisar = document.createElement('button');
            revisar.type = 'button';
            revisar.textContent = 'Autorizar registro con importes originales';
            revisar.addEventListener('click', async () => {
                if (!window.confirm('Solo administrador o supervisor: revisa los productos y confirma que estos importes ya se cobraron. ¿Registrar la venta con esos precios y comisión originales?')) return;
                revisar.disabled = true;
                try {
                    await NuviEmergencia.revisarVenta(venta);
                    avisar('Venta registrada. No vuelvas a cobrarla.');
                    await mostrarPendientes();
                } catch (error) {
                    avisar('No se pudo confirmar o actualizar la venta. Podría estar registrada: no la vuelvas a cobrar. Reintenta o solicita ayuda: ' + error.message);
                    revisar.disabled = false;
                }
            });
            tarjeta.append(revisar);
            detalle.append(tarjeta);
        });
    }

    async function sincronizar() {
        if (!catalogo || sincronizando) return;
        sincronizando = true;
        $('sincronizar').disabled = true;
        try {
            const resultado = await NuviEmergencia.sincronizar(catalogo.id_usuario);
            await mostrarPendientes();
            if (resultado.errores.length) avisar(resultado.errores.join('\n'));
            else if (!resultado.pendientes) avisar('No hay ventas de emergencia pendientes.');
        } catch (error) {
            avisar('No se pudieron revisar las ventas: ' + error.message);
        } finally {
            sincronizando = false;
            $('sincronizar').disabled = false;
        }
    }

    $('cobrar').addEventListener('click', async () => {
        if (guardando || !catalogo || !carrito.size) return;
        const metodo = $('metodo').value;
        const terminal = terminalActual();
        const cobroTarjeta = importeTarjeta(terminal);
        if (metodo === 'efectivo' && (totalCentavos <= 0 || !/^\d+(?:\.\d{0,2})?$/.test(recibido.value) || Math.round(Number(recibido.value) * 100) < totalCentavos)) {
            avisar('Escribe el efectivo recibido. Debe cubrir el total.');
            return;
        }
        if (totalCentavos <= 0 || (metodo === 'tarjeta' && cobroTarjeta === null)) {
            avisar('No hay un importe o una configuración de terminal válida. No guardes el cobro.');
            return;
        }
        if (metodo !== 'efectivo' && !window.confirm(metodo === 'tarjeta'
            ? `¿La terminal aprobó el pago de ${dinero(cobroTarjeta)}?`
            : `¿Ya confirmaste la transferencia de ${dinero(totalCentavos)}?`)) return;
        guardando = true;
        renderizar();
        let guardada = false;
        try {
            const fecha = new Date();
            const clave = NuviEmergencia.claveVenta();
            const productos = Array.from(carrito, ([id, linea]) => ({
                id: linea.producto.id, nombre: linea.producto.nombre,
                precio: Number(linea.producto.precio_venta), cantidad: linea.cantidad,
                ...(linea.granel ? { origen_granel: true, redondeo_granel: linea.redondeo, linea_granel: id } : {})
            }));
            const venta = {
                id: clave, id_usuario: catalogo.id_usuario, createdAt: fecha.toISOString(),
                payload: {
                    idempotency_key: clave, id_usuario_origen: catalogo.id_usuario,
                    venta_emergencia: true, total: totalCentavos / 100, metodo_pago: metodo,
                    ...(metodo === 'efectivo' ? { pago_con_efectivo: Number(recibido.value) } : {}),
                    ...(metodo === 'tarjeta' ? { id_terminal_pago: terminal.id, terminal_emergencia: terminal, cobro_tarjeta_emergencia: cobroTarjeta / 100 } : {}),
                    nota_venta: `Venta de emergencia registrada en tablet: ${fecha.toISOString()}`,
                    productos
                }
            };
            const cambio = Math.round(Number(recibido.value) * 100) - totalCentavos;
            await NuviEmergencia.escribirVenta(venta);
            guardada = true;
            carrito.clear();
            recibido.value = '';
            avisar(`Venta guardada en esta tablet.${metodo === 'efectivo' ? ` Cambio: ${dinero(cambio)}.` : ''} Falta enviarla al servidor.`);
            await mostrarPendientes();
            buscar.focus();
        } catch (error) {
            avisar(guardada
                ? 'La venta sí quedó guardada, pero no se pudo actualizar el indicador de pendientes. No la vuelvas a cobrar.'
                : 'No se guardó la venta. No vuelvas a cobrar: conserva los datos y solicita ayuda. ' + error.message);
        } finally {
            guardando = false;
            renderizar();
        }
    });

    $('sincronizar').addEventListener('click', sincronizar);
    window.addEventListener('online', sincronizar);
    window.addEventListener('beforeunload', evento => {
        if (!carrito.size && !guardando) return;
        evento.preventDefault();
        evento.returnValue = '';
    });

    NuviEmergencia.leerCatalogo().then(async guardado => {
        if (!guardado || !guardado.id_usuario || !Array.isArray(guardado.productos) || !guardado.productos.length) {
            throw new Error('Esta tablet aún no está preparada. Abre la caja normal con conexión antes de un apagón.');
        }
        catalogo = guardado;
        try {
            const cache = JSON.parse(localStorage.getItem('nuvi_terminales_cache_v1') || 'null');
            if (cache && cache.data && (cache.data.configuracion_lista === true || cache.data.configuracion_lista === 1 || cache.data.configuracion_lista === '1') && Array.isArray(cache.data.terminales)) {
                terminales = cache.data.terminales.filter(terminal => Number(terminal.id) > 0);
            }
        } catch (error) {
            console.error('No se pudo leer la configuración de terminales:', error);
        }
        terminales.forEach(terminal => {
            const opcion = document.createElement('option');
            opcion.value = String(terminal.id);
            opcion.textContent = terminal.nombre;
            $('terminal').append(opcion);
        });
        const predeterminada = terminales.find(terminal => Number(terminal.es_predeterminada) === 1);
        if (predeterminada) $('terminal').value = String(predeterminada.id);
        mostrarGranel();
        $('estado').textContent = `Caja preparada por ${guardado.nombre || 'la cuenta de caja'}. Catálogo guardado: ${new Date(guardado.actualizado).toLocaleString('es-MX')}.`;
        await mostrarPendientes();
        renderizar();
        buscar.focus();
        sincronizar();
    }).catch(error => {
        $('estado').textContent = 'Caja de emergencia no disponible: ' + error.message;
        $('sincronizar').disabled = true;
        buscar.disabled = true;
        console.error('Caja de emergencia:', error);
    });
})();
