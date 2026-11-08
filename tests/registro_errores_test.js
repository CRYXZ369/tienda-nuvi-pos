(async () => {
    'use strict';
    const salida = document.getElementById('resultado');
    const clave = 'nuvi_reportes_error_987654321';
    const enviados = [];
    let servidor = false;
    let confirmar = false;
    let fallaInventario = true;
    const verificar = (condicion, mensaje) => {
        if (!condicion) throw new Error(mensaje);
    };
    const esperar = async condicion => {
        for (let i = 0; i < 100; i++) {
            if (condicion()) return;
            await new Promise(resolve => setTimeout(resolve, 10));
        }
        throw new Error('Tiempo agotado esperando resultado');
    };
    const cola = () => JSON.parse(localStorage.getItem(clave) || '[]');
    try {
        localStorage.removeItem(clave);
        window.fetch = async (input, opciones) => {
            const ruta = new URL(input, location.href).pathname.split('/').pop();
            if (ruta === 'registrar_error.php') {
                if (!servidor) throw new TypeError('Sin conexión simulada');
                if (!confirmar) return new Response('{"success":false}', { status: 200 });
                enviados.push(JSON.parse(opciones.body));
                return new Response('{"success":true}', { status: 200 });
            }
            if (ruta === 'actualizar_inventario.php') {
                return new Response(fallaInventario ? '{"status":"error","message":"El precio debe ser válido."}' : '{"status":"ok"}', {
                    status: fallaInventario ? 422 : 200
                });
            }
            if (ruta === 'finalizar_venta.php') {
                return new Response('{"error":"CSRF_INVALID"}', { status: 403 });
            }
            throw new Error('Ruta inesperada');
        };
        const script = document.createElement('script');
        script.src = '../js/registro-errores.js';
        script.dataset.usuario = '987654321';
        script.dataset.csrf = 'token-prueba';
        await new Promise((resolve, reject) => {
            script.onload = resolve;
            script.onerror = reject;
            document.head.append(script);
        });
        const formulario = new FormData();
        formulario.append('id', '123');
        formulario.append('columna', 'precio_venta');
        formulario.append('valor', 'DATO_PRIVADO_PRUEBA');
        const respuesta = await fetch('../actualizar_inventario.php', { method: 'POST', body: formulario });
        verificar(respuesta.status === 422 && (await respuesta.json()).status === 'error', 'Se alteró la respuesta original');
        await esperar(() => cola().length === 1);
        verificar(cola()[0].codigo === 'PRECIO_INVALIDO' && cola()[0].producto_id === 123, 'No se clasificó el error de inventario');
        verificar(!JSON.stringify(cola()).includes('DATO_PRIVADO_PRUEBA'), 'Se registró el valor editado');
        const panel = document.getElementById('nuvi-reporte-error');
        verificar(panel && !panel.hidden, 'No aparece la opción de explicar el error');
        panel.querySelector('textarea').value = 'No se guardó el precio';
        panel.querySelector('details button').click();
        verificar(cola().length === 2 && cola()[0].id === cola()[1].id, 'El comentario no está vinculado');
        fallaInventario = false;
        const correcta = await fetch('../actualizar_inventario.php', { method: 'POST', body: formulario });
        verificar(correcta.ok && (await correcta.json()).status === 'ok', 'Se alteró la respuesta correcta');
        await new Promise(resolve => setTimeout(resolve, 50));
        verificar(cola().length === 2, 'Se registró una operación correcta como error');
        servidor = true;
        window.dispatchEvent(new Event('online'));
        await new Promise(resolve => setTimeout(resolve, 50));
        verificar(cola().length === 2, 'Se descartó un reporte sin confirmación');
        confirmar = true;
        window.dispatchEvent(new Event('online'));
        await esperar(() => cola().length === 0);
        verificar(enviados.length === 2, 'No se enviaron ambos reportes');
        const csrf = await fetch('../finalizar_venta.php', { method: 'POST' });
        verificar(csrf.status === 403, 'Se alteró la respuesta CSRF');
        await esperar(() => enviados.some(evento => evento.codigo === 'CSRF_INVALID'));
        salida.textContent = 'OK';
    } catch (error) {
        salida.textContent = 'ERROR: ' + error.message;
        console.error(error);
    }
})();
