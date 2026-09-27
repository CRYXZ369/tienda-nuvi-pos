<?php
// DEBUG: las siguientes líneas estaban activadas; se comentan para producción.
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

require_once('auth.php'); // Autentica que el usuario es valido
require_once("conexion.php"); //valida la conexion con el servidor apache
require_once('auditoria_utils.php');

/**
 * Centraliza el resultado del registro para que nunca se mande al cajero a
 * una pantalla genérica de inventario o a un error técnico.
 */
function redirigirResultadoProducto($estado, $productoId = 0, $codigo = '') {
    $productoId = (int) $productoId;
    $codigo = (string) $codigo;
    $parametros = ['estado' => $estado];
    if ($productoId > 0) {
        $parametros['producto_id'] = $productoId;
    }
    if ($codigo !== '') {
        $parametros['q'] = $codigo;
    }

    header('Location: resultado_registro_producto.php?' . http_build_query($parametros));
    exit;
}

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    redirigirResultadoProducto('error_conexion');
}

// 2. Recibimos datos (añadimos marca que está en tu tabla)
// Recibimos y saneamos datos
$codigo = trim($_POST['codigo_barras'] ?? '');
$nombre = trim($_POST['nombre'] ?? '');
$unidad = trim($_POST['unidad_medida'] ?? 'pza');
$marca  = trim($_POST['marca'] ?? '');
$compra = floatval($_POST['precio_compra'] ?? 0);
$venta  = floatval($_POST['precio_venta'] ?? 0);
$stock  = floatval($_POST['stock'] ?? 0);
$cat    = trim($_POST['categoria'] ?? '');

if (!in_array($unidad, ['pza', 'kg', 'g', 'L', 'ml'], true)) {
    $unidad = 'pza';
}

if ($codigo === '' || $nombre === '' || $marca === '' || $cat === '' || $compra < 0 || $venta <= 0 || $stock < 0) {
    redirigirResultadoProducto('datos_invalidos');
}

// 3. SQL corregido con la columna 'marca'
$sql = "INSERT INTO productos (codigo_barras, nombre, marca, unidad_medida, precio_compra, precio_venta, stock, categoria)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

// Preparación y ejecución con saneamiento adicional
$stmt = mysqli_prepare($conexion, $sql);
if ($stmt) {
    // Tipos: código, nombre, marca, unidad, compra, venta, stock decimal y categoría.
    mysqli_stmt_bind_param($stmt, "ssssddds", $codigo, $nombre, $marca, $unidad, $compra, $venta, $stock, $cat);

    // Algunos servidores tienen MySQLi en modo estricto: un código repetido
    // lanza una excepción 1062 en lugar de devolver false. La convertimos en
    // un resultado normal para que el cajero nunca vea una página 500.
    $guardado = false;
    $codigoError = 0;
    try {
        $guardado = mysqli_stmt_execute($stmt);
    } catch (Throwable $error) {
        $codigoError = (int) $error->getCode();
        if ($codigoError !== 1062) {
            error_log('guardar_producto.php execute error: ' . $error->getMessage());
        }
    }

    if ($guardado) {
        $idProducto = (int) mysqli_insert_id($conexion);
        mysqli_stmt_close($stmt);
        nuviRegistrarAuditoria(
            $conexion,
            'producto_creado',
            'producto',
            $idProducto,
            'Producto registrado: ' . $nombre,
            [
                'codigo_barras' => $codigo,
                'precio_compra' => $compra,
                'precio_venta' => $venta,
                'stock_inicial' => $stock,
                'categoria' => $cat,
            ]
        );
        redirigirResultadoProducto('guardado', $idProducto, $codigo);
    } else {
        if ($codigoError === 1062 || mysqli_stmt_errno($stmt) === 1062) {
            mysqli_stmt_close($stmt);
            // El índice único ya confirma el duplicado. No hace falta otra
            // consulta para conocer el ID: Inventario puede ubicarlo por su
            // código y evitamos que un fallo auxiliar bloquee al cajero.
            redirigirResultadoProducto('duplicado', 0, $codigo);
        } else {
            // Mostrar mensaje genérico y loguear el detalle (si existe un logger)
            error_log('guardar_producto.php MySQL error: ' . mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
            redirigirResultadoProducto('error_guardando');
        }
    }
} else {
    error_log('guardar_producto.php prepare error: ' . mysqli_error($conexion));
    redirigirResultadoProducto('error_consulta');
}
?>
