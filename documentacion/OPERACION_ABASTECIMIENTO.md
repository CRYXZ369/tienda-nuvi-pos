# Abastecimiento, lotes y utilidad

## Activación única

Un administrador abre **Abastecimiento** desde el encabezado y usa
**Preparar estructura ahora**. La migración sólo crea tablas nuevas e incorpora
`detalle_ventas.costo_unitario`; no borra ni modifica ventas, clientes o
productos existentes.

## Flujo diario

1. Registra primero al proveedor, si aplica.
2. En **Registrar compra**, elige proveedor, folio y cada producto recibido.
3. Captura la cantidad, el costo por unidad y, cuando exista, caducidad/lote.
   El costo se propone desde el último precio de compra, pero se puede ajustar.
4. Al guardar, Nuvi suma existencias, actualiza el último costo de compra,
   registra el lote y deja el movimiento en bitácora.
5. Usa **Registrar merma** para producto caducado, dañado o perdido. Nunca
   reduzcas el stock sólo para ocultar una pérdida: la merma conserva motivo y
   costo para reportes.

## Caducidad

Los lotes capturados aparecen durante los 30 días previos a caducar. Las ventas
nuevas consumen primero los lotes con fecha más próxima. El stock que ya existía
antes de activar este módulo no recibe una fecha inventada, así que no aparece
como próximo a caducar hasta que se registre una compra o lote real.

## Utilidad

La tarjeta **Utilidad estimada** compara ventas de productos contra su costo y
resta la merma del periodo. A partir de la activación, cada venta guarda el
`costo_unitario` que tenía al realizarse. Para ventas históricas sin esa columna
se usa el último costo conocido del producto; por eso el sistema lo etiqueta
como estimado. El selector permite ver 7, 30 o 90 días y desglosa por categoría.

Las compras no se restan otra vez de la utilidad: son entrada de inventario. La
utilidad reconoce el costo cuando se vende el artículo y el costo de la merma
cuando se pierde.

## Continuidad offline

La cola de ventas pendientes usa IndexedDB y migra automáticamente la cola
anterior de localStorage. Esto conserva mejor ventas pendientes después de
cerrar o reiniciar el navegador. La instalación PWA que permite abrir el POS
sin red desde cero requiere HTTPS en la URL LAN; un service worker no es fiable
en `http://192.168.x.x`. Hasta contar con HTTPS, conserva la pestaña abierta
durante una caída y usa la URL LAN, no Tailscale.
