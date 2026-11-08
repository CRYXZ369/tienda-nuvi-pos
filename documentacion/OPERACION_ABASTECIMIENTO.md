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
cerrar o reiniciar el navegador. La caja de emergencia es una pantalla independiente para registrar ventas
cuando el servidor o la red se apagan. Antes de un apagón, abre
la caja normal con HTTPS en la tablet y espera a que cargue el catálogo; la
instalación debe tener un service worker activo y almacenamiento disponible.
Si se pierde la conexión mientras estás en inventario, abre **Punto de venta**:
la pantalla de emergencia debe aparecer tras unos segundos.

Las ventas quedan en IndexedDB en esa tablet, separadas de la cola anterior.
Al volver el servidor, entra a la caja normal con la misma cuenta o usa
**Enviar ventas pendientes**. No borres datos del navegador ni desinstales la
PWA mientras existan pendientes. El almacenamiento del navegador puede perderse;
no reemplaza los respaldos. Si el catálogo o el precio cambiaron, la venta
permanece pendiente y necesita revisión; nunca se descarta automáticamente.
Todavía no existe un flujo para resolver y contabilizar esas ventas con el
precio original; por ello esta versión no está lista para producción.
La fecha y hora de la tablet quedan en la nota de la venta, mientras que la
fecha principal del servidor corresponde a la sincronización.

La caja de emergencia no verifica contraseñas sin servidor y utiliza la última
cuenta que preparó el catálogo. Usa solo dispositivos de confianza. No conecta
las demás cajas ni imprime tickets durante el apagón. Acepta efectivo, tarjeta
y transferencia, pero no fiado ni pagos mixtos. Comprueba en la terminal que
el pago con tarjeta fue aprobado y confirma las transferencias en la cuenta:
esta pantalla no procesa pagos. Tarjeta requiere la configuración de terminales
guardada antes del apagón; si cambia la comisión o la terminal deja de estar
activa, la venta queda pendiente para revisión.
Los productos a granel se agregan por importe (opción predeterminada) o por
cantidad pesada (hasta tres decimales). La cantidad por importe se calcula
dividiendo el importe entre el precio unitario; el inventario almacena tres
decimales. Los accesos se agrupan automáticamente por categoría y tipo a partir
del catálogo y no sustituyen al buscador. La base debe tener
preparada la migración de cantidades decimales para poder sincronizarlos.
Si la tablet se queda sin batería o no estaba preparada, no se puede garantizar
la operación. En `http://192.168.x.x` el service worker no es fiable: usa HTTPS
confiable en la misma dirección de la tienda.
