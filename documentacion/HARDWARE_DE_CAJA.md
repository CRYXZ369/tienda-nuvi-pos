# Hardware recomendado para caja

Esta guía evita comprar equipo que sólo funcione con una aplicación propietaria
o que dependa de Internet. Antes de pagar, comparte la publicación y confirma
con el vendedor los puntos marcados como obligatorios.

## Lector de código de barras

Compra un **lector de imagen 2D (area imager)**, no sólo un láser 1D. Aunque la
tienda use códigos de barras normales, el lector de imagen suele leer mejor
etiquetas brillosas, algo dobladas o poco contrastadas y deja lista la caja
para QR en el futuro.

Debe tener:

- **USB HID Keyboard / plug and play**: se comporta como teclado; no requiere
  programa, Internet ni driver especial.
- Lectura de **EAN-13, EAN-8, UPC-A, UPC-E, Code 128, Code 39 e ITF**. Que
  incluya QR/Data Matrix es recomendable, no obligatorio para vender hoy.
- Iluminación LED propia, apuntador y soporte de escritorio.
- Configuración para enviar **Enter al final** de cada lectura.
- Cable USB-A de al menos 1.5 m y resistencia razonable a caídas.

Para este caso, busca preferentemente un modelo con **Bluetooth HID + USB HID**
y base/soporte de escritorio: se enlaza a la tablet como teclado Bluetooth y,
si hiciera falta, se conecta por USB a una PC. También sirve USB OTG compatible
con esa tablet. Un lector sólo RS-232, conector PS/2 o que dependa de una app
del fabricante no es conveniente para Nuvi.

## Impresora de tickets

Recomendación: térmica directa de **80 mm**, no 58 mm. El ticket de 80 mm es
más legible, admite nombres largos y el papel es fácil de conseguir. No usa
tinta.

Para una caja conectada por PC/laptop, busca:

- Ancho de papel **80 mm**, cortador automático y velocidad de al menos
  200 mm/s.
- **USB + Ethernet (RJ45)**; Bluetooth o Wi-Fi son extras útiles, no sustituto
  de una de esas dos conexiones principales.
- Driver para Windows 10/11 o Linux y compatibilidad **ESC/POS**.
- Opción de abrir cajón de dinero RJ11, si se planea usar cajón después.

Para tablet Android, la compra más flexible lleva **Bluetooth + Wi-Fi o
Ethernet**, además de USB. Bluetooth da una conexión directa y cómoda a la
tablet; Wi-Fi/Ethernet permite compartir la impresora después con otros
equipos. Confirma con el vendedor: “¿puede imprimir desde el diálogo de
impresión de Chrome en Android, sin usar solamente su app propietaria?”. Muchas
térmicas Bluetooth baratas sólo imprimen desde su propia app; no son la mejor
opción para Nuvi. Si usa Wi-Fi de 2.4 GHz, confirma que la tablet también pueda
conectarse a esa red y reserva una IP fija/DHCP para la impresora.

## Varios dispositivos: decisión práctica

Bluetooth normalmente queda enlazado a **un solo dispositivo a la vez**. Por
eso no conviene comprar un lector o impresora Bluetooth pensando que PC y varias
tablets podrán usarlo simultáneamente.

- **Lector:** cada caja debe tener su propio lector. El modelo Bluetooth HID +
  USB HID deja usarlo hoy con la tablet y moverlo a una PC después, pero no
  escanear en ambas a la vez.
- **Impresora:** el Bluetooth sirve para la tablet principal. Para que varias
  cajas impriman en una sola térmica, se usa su Wi-Fi/Ethernet en la LAN, no
  Bluetooth. Por eso la especificación ideal es: **80 mm, ESC/POS, USB +
  Bluetooth + Wi-Fi/Ethernet y cortador automático**.

Antes de comprar, pide al vendedor confirmar por escrito compatibilidad con
Android/Chrome mediante servicio de impresión y prueba de impresión por red;
tener Wi-Fi o Bluetooth no garantiza por sí solo que aparezca en el diálogo de
impresión de Android.

## Qué ya prepara Nuvi

La térmica Ethernet se conecta por cable al switch/router de la sucursal. Las
tablets permanecen en el Wi-Fi de esa misma LAN; no necesitan Bluetooth. En
**Historial → Configurar terminales** un administrador guarda la IP de la
impresora, activa la impresión por LAN y pulsa **Imprimir prueba**. La IP debe
quedar reservada en el router/DHCP para que no cambie.

Después de una venta confirmada aparece **🖨 Imprimir último ticket**. El POS
lo manda al servidor local y éste usa ESC/POS por la red a la térmica. No se
imprime automáticamente, para evitar duplicar papel o tickets de ventas que
aún están pendientes de sincronizar. Si no hay una térmica LAN configurada,
**🧾 Último ticket** conserva el diálogo de impresión/PDF del navegador.

Cuando llegue la impresora:

1. Conéctala por Ethernet al switch/router y a la energía respaldada por UPS.
2. Imprime su hoja de autoprueba, identifica la IPv4 y regístrala en Nuvi.
3. Envía **Imprimir prueba** antes de usar una venta real.
4. En el POS realiza una venta real pequeña y pulsa **Imprimir último ticket**.
5. Si se desea personalizar nombre, dirección, teléfono o leyenda, copia
   `config/ticket.example.php` como `config/ticket.local.php` y completa los
   valores. Ese archivo no se sube a GitHub.

El ticket es comprobante interno de venta; no sustituye una factura fiscal.
