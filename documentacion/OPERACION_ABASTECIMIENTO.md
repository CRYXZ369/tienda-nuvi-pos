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

Para registrar una compra, selecciona el proveedor de la operación. Puedes
filtrar el listado por la marca o proveedor habitual que figura en inventario:
esto sólo facilita la búsqueda y no asigna automáticamente proveedores.
Escanea el código en el campo correspondiente y pulsa Enter si el lector no lo
envía; elige la cantidad y revisa el costo propuesto antes de guardar. La merma
también permite seleccionar por código. La operación sólo modifica existencias
al guardar el formulario; un código no encontrado no agrega ningún producto.

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
instalación debe tener HTTPS confiable, un service worker activo y almacenamiento
disponible. Comprueba en cada tablet la preparación, el apagón simulado y la
sincronización antes de depender de esta función.

Si se pierde la conexión, un aviso ofrece abrir la caja de emergencia en otra
pestaña. La pantalla actual no se cierra, para conservar el carrito o los
cambios de inventario sin guardar. En el punto de venta puedes cerrar el aviso
y continuar en la caja normal si sigue funcionando. Si el aviso indica que no está preparada,
no se puede usar esa caja hasta prepararla con conexión. Al navegar, también se
abre la pantalla de emergencia si el servidor responde con un error 5xx.

En la caja de emergencia, un código exacto se agrega con un escaneo, incluso
si el lector no envía Enter; los productos a granel abren la selección de
importe o peso. El indicador rojo señala que el servidor o la base de datos no
están disponibles; amarillo indica que hay que revisar la sesión o la cuenta;
verde confirma que la sesión y una consulta a la base responden. Revisa las
ventas pendientes antes de volver a la caja normal: el carrito que aún no
guardaste no se transfiere automáticamente. El indicador se actualiza al
regresar a la pantalla y aproximadamente cada 15 segundos.

Las ventas quedan en IndexedDB en esa tablet, separadas de la cola anterior.
Al volver el servidor, entra a la caja normal con la misma cuenta o usa
**Enviar ventas pendientes**. No borres datos del navegador ni desinstales la
PWA mientras existan pendientes. El almacenamiento del navegador puede perderse;
no reemplaza los respaldos. Si el catálogo o el precio cambiaron, la venta
permanece pendiente y necesita revisión; nunca se descarta automáticamente.
Después de intentar el envío desde la caja de emergencia, cada venta rechazada
muestra el motivo en su detalle durante esa visita. Si se pierde una respuesta,
podría haberse registrado: reintenta el envío y no cobres otra vez. Los motivos
no se conservan al cerrar la página; la venta pendiente sí permanece en la tablet.
Si el catálogo guardado deja de estar disponible, la caja no permite nuevas
ventas, pero todavía muestra el detalle de las ventas pendientes que permanezcan
en la tablet para que un administrador pueda revisarlas.
Un administrador o supervisor puede revisar el detalle pendiente y autorizar
su registro con los importes originales. La revisión exige iniciar sesión en
la tablet con conexión y no vuelve a cobrar al cliente. El registro conserva
la cuenta original y anota quién autorizó la revisión. Si falta un producto,
la cuenta original o la terminal ya no existe, o si cambió la unidad de medida,
la venta sigue pendiente para revisión manual. Un producto pausado o una terminal
desactivada pueden utilizarse únicamente al autorizar la revisión de una venta
ya cobrada, sin reactivarlos.
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
Las categorías `Fruto` y `Frutos` se agrupan como `Frutos` sin modificar el
inventario. Los productos que no forman una familia se muestran directamente,
sin una pantalla adicional de «Otros productos». El orden usa la puntuación
de uso del dispositivo; en la caja normal también se carga la puntuación remota
de la cuenta. Las ventas de emergencia actualizan el orden local después de
guardarse, sin modificar la venta ni depender de la conexión.
Si la tablet se queda sin batería o no estaba preparada, no se puede garantizar
la operación. En `http://192.168.x.x` el service worker no es fiable: usa HTTPS
confiable en la misma dirección de la tienda.

## Diagnóstico de errores

En las pantallas con encabezado de Nuvi se registran errores inesperados del
navegador. También se detectan las respuestas fallidas y problemas de conexión
al finalizar una venta, registrar un fiado o actualizar el inventario. En estos
casos aparece un aviso discreto para explicar opcionalmente qué ocurrió.
Administración consulta los registros en **Bitácora operativa**, filtrando el
área **diagnostico**; la referencia relaciona el error con su comentario. La
fecha de la bitácora es la de recepción y `fecha_dispositivo` indica cuándo
ocurrió según el reloj del dispositivo.

Para proteger la información, no se registran automáticamente carritos, valores
editados, contraseñas, tokens, datos de pago ni respuestas completas. Para
inventario se conserva el ID del producto y el campo, y algunos errores se
clasifican con códigos como `CSRF_INVALID`. No escribas información sensible o
personal en la explicación. Los errores internos de PHP siguen en el log del
servidor; no todos los errores capturados por código, de la caja de emergencia,
ni una pantalla que no llegó a cargar pueden registrarse desde el navegador.

Si no hay servidor, el reporte queda pendiente en el almacenamiento local de
esa cuenta y se reintenta al recuperar la conexión o abrir una pantalla de
Nuvi con la misma cuenta. Se conservan hasta 50 reportes por cuenta y dispositivo;
si el almacenamiento está lleno o deshabilitado, el aviso indica que no se
pudo guardar. Estos reportes son independientes de las ventas offline y pueden
perderse si se borran los datos del navegador. La bitácora debe estar preparada
desde Configuración de terminales; si falla, el reporte permanece pendiente y
se registra el fallo en el log de PHP. No borres datos de la PWA para probarlo.
