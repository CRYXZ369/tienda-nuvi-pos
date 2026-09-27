<?php
// ============================================
// BÚSQUEDA UNIVERSAL - Consolidada
// Reemplaza buscar_producto.php y buscar_granel.php
// ============================================
header('Content-Type: application/json');
require_once('auth.php');
require_once("conexion.php");

$tipo = $_GET['tipo'] ?? 'producto'; // 'producto' o 'granel'
$busqueda = trim($_GET['q'] ?? $_POST['q'] ?? '');

if (!$busqueda || strlen($busqueda) < 2) {
    echo json_encode([]);
    exit;
}

// Búsqueda inteligente por palabras
$palabras = explode(' ', strtolower($busqueda));
$busqueda_sql = "%$busqueda%";

// Query mejorada
$sql = "SELECT id, codigo_barras, nombre, marca, precio_venta, stock, categoria, unidad_medida
        FROM productos
        WHERE (codigo_barras LIKE ? OR nombre LIKE ? OR marca LIKE ? OR categoria LIKE ?)
        AND stock > 0 AND (activo IS NULL OR activo = 1)
        ORDER BY
            CASE
                WHEN codigo_barras = ? THEN 0
                WHEN nombre LIKE ? THEN 1
                ELSE 2
            END,
            nombre ASC
        LIMIT 10";

$stmt = mysqli_prepare($conexion, $sql);
if (!$stmt) {
    error_log('buscar.php: ' . mysqli_error($conexion));
    echo json_encode(["error" => "No se pudo realizar la búsqueda"]);
    exit;
}

$nombre_like = "%$busqueda%";
$marca_like = "%$busqueda%";
$categoria_like = "%$busqueda%";
$codigo_exacto = $busqueda;
$nombre_priority = "%$busqueda%";

mysqli_stmt_bind_param($stmt, "ssssss",
    $busqueda_sql, $nombre_like, $marca_like, $categoria_like,
    $codigo_exacto, $nombre_priority
);

mysqli_stmt_execute($stmt);
$resultado = mysqli_stmt_get_result($stmt);

$productos = [];
while ($fila = mysqli_fetch_assoc($resultado)) {
    $productos[] = [
        'id' => $fila['id'],
        'codigo_barras' => $fila['codigo_barras'],
        'nombre' => $fila['nombre'],
        'marca' => $fila['marca'],
        'precio_venta' => floatval($fila['precio_venta']),
        'stock' => floatval($fila['stock']),
        'categoria' => $fila['categoria'],
        'unidad_medida' => $fila['unidad_medida']
    ];
}

echo json_encode($productos);
?>
