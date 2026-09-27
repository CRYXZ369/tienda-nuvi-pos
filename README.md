# Tienda Nuvi POS

Sistema web de punto de venta e inventario escrito en PHP y JavaScript. Incluye
ventas, lector de códigos, productos a granel, libreta de clientes, cortes de
caja y una cola local para tolerar interrupciones de red.

## Estado de esta copia

La instalación crea una base vacía y un primer administrador con contraseña
elegida por quien instala. No usa ni incluye datos de una tienda real.

## Requisitos conocidos

- PHP 8 o posterior con las extensiones `mysqli` y `json`.
- MySQL o MariaDB.
- Apache o Nginx configurado para servir PHP.

## Instalación en Linux

En Fedora, Debian o Ubuntu, clona o descarga el repositorio y ejecuta:

```bash
sudo bash install.sh
```

El instalador prepara Apache, PHP y MariaDB, crea una base de datos y un usuario
SQL exclusivos, pide los datos del administrador y aplica las migraciones. No
sobrescribe una carpeta de aplicación con contenido ni una base ya existente.
La contraseña del administrador debe tener al menos 12 caracteres.

## Instalación en Windows

Se requiere XAMPP instalado en `C:\xampp`, con Apache y MySQL/MariaDB
iniciados. El instalador Windows se compila desde
`installer/windows/setup_POS_nuvi.iss` con Inno Setup 6; el script
`installer/windows/install_windows.ps1` configura la base y solicita el
administrador. GitHub Actions puede compilarlo al publicar una etiqueta `v*`.

Al publicar una etiqueta `v*`, GitHub Actions compila el instalador y lo adjunta
a la Release de esa versión. También puedes compilarlo localmente con
`installer/windows/build.ps1`; el código fuente está en `installer/windows/`.

No subas `config/local.php`, respaldos, registros, credenciales ni datos de
clientes o ventas. Los archivos de configuración local están excluidos por
`.gitignore`. Para la instalación manual, copia `config/local.example.php` como
`config/local.php` y completa los datos de una base de desarrollo vacía.

## Módulos principales

- `punto_de_venta.php`: cobro, lector, granel y cola de ventas sin conexión.
- `consultar_productos.php` y `obtener_productos_criticos.php`: inventario.
- `corte_caja.php` y `consultar_cortes.php`: cortes y consulta histórica.
- `reportes.php` y `exportar_reporte.php`: reportes operativos.
- `ventas_utils.php` y `pagos_venta_utils.php`: validación de ventas y pagos.
- `documentacion/`: guías generales de operación y hardware.

Antes de usarlo con datos reales, configura HTTPS y respaldos, y prueba los
flujos en una instancia aislada. No expongas el POS directamente a Internet.

## Licencia

Este proyecto se distribuye bajo la licencia MIT. Consulta [LICENSE](LICENSE).
