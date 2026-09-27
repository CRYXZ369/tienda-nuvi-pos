<?php
// Entrada única para la carpeta /Tienda/. Evita que Apache intente listar el
// directorio y hace que la PWA siempre comience en el punto de venta.
header('Location: punto_de_venta.php', true, 302);
exit;
