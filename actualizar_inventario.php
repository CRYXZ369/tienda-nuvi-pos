<?php
/** Actualizaciones inline del inventario: sesión, lista blanca y bajas lógicas. */
header('Content-Type: application/json; charset=utf-8');
require_once('auth.php');
require_once('conexion.php');
require_once('auditoria_utils.php');

function responderInventario(string $estado, string $mensaje, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode(['status' => $estado, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($_SESSION['rol'] ?? '', ['admin', 'supervisor'], true)) {
    responderInventario('error', 'No tienes permiso para modificar el inventario.', 403);
}
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    responderInventario('error', 'No fue posible conectar con la base de datos.', 503);
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    responderInventario('error', 'Producto inválido.', 422);
}

$tieneActivo = mysqli_query($conexion, "SHOW COLUMNS FROM productos LIKE 'activo'");
$hayActivo = $tieneActivo && mysqli_num_rows($tieneActivo) > 0;

try {
    if (($_POST['accion'] ?? '') === 'eliminar') {
        // Nunca se borra una fila: se pausa y queda íntegra para auditoría,
        // ventas históricas y una posible reactivación.
        if (($_SESSION['rol'] ?? '') !== 'admin') {
            throw new Exception('Sólo administración puede desactivar productos.');
        }
        if (!$hayActivo) {
            throw new Exception('La base aún no tiene el campo de estado del producto.');
        }
        $productoStmt = mysqli_prepare($conexion, 'SELECT nombre, codigo_barras FROM productos WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($productoStmt, 'i', $id);
        mysqli_stmt_execute($productoStmt);
        $producto = mysqli_fetch_assoc(mysqli_stmt_get_result($productoStmt));
        if (!$producto) {
            throw new Exception('No se encontró el producto.');
        }
        $stmt = mysqli_prepare($conexion, 'UPDATE productos SET activo = 0 WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
            throw new Exception('No se encontró el producto o no se pudo desactivar.');
        }
        nuviRegistrarAuditoria(
            $conexion,
            'producto_desactivado',
            'producto',
            $id,
            'Producto desactivado: ' . (string) $producto['nombre'],
            ['codigo_barras' => $producto['codigo_barras'] ?? null]
        );
        responderInventario('ok', 'Producto desactivado; su historial se conserva.');
    }

    $columna = trim((string) ($_POST['columna'] ?? ''));
    $valor = $_POST['valor'] ?? null;
    $permitidas = ['nombre', 'marca', 'precio_venta', 'precio_compra', 'stock', 'categoria', 'codigo_barras'];
    if ($hayActivo) {
        $permitidas[] = 'activo';
    }
    if (!in_array($columna, $permitidas, true)) {
        throw new Exception('Campo no permitido.');
    }

    $productoStmt = mysqli_prepare($conexion, "SELECT nombre, `{$columna}` AS valor_anterior FROM productos WHERE id = ? LIMIT 1");
    if (!$productoStmt) {
        throw new Exception('No se pudo consultar el producto.');
    }
    mysqli_stmt_bind_param($productoStmt, 'i', $id);
    mysqli_stmt_execute($productoStmt);
    $productoAnterior = mysqli_fetch_assoc(mysqli_stmt_get_result($productoStmt));
    if (!$productoAnterior) {
        throw new Exception('No se encontró el producto.');
    }

    if (in_array($columna, ['precio_venta', 'precio_compra'], true)) {
        $valor = (float) str_replace(',', '.', (string) $valor);
        if ($valor < 0 || ($columna === 'precio_venta' && $valor <= 0)) {
            throw new Exception('El precio debe ser válido.');
        }
        $tipo = 'di';
    } elseif ($columna === 'stock') {
        $stockTexto = str_replace(',', '.', trim((string) $valor));
        if ($stockTexto === '' || $stockTexto === '-' || preg_match('/^-0(?:\.0+)?$/D', $stockTexto)) {
            $stockTexto = '0';
        }
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $stockTexto) || !is_finite((float) $stockTexto)) {
            throw new Exception('Escribe una cantidad de existencias válida, igual o mayor a cero.');
        }
        $valor = (float) $stockTexto;
        $tipo = 'di';
    } elseif ($columna === 'activo') {
        $valor = in_array((string) $valor, ['0', 'false'], true) ? 0 : 1;
        $tipo = 'ii';
    } else {
        $valor = trim((string) $valor);
        if ($columna === 'nombre' && $valor === '') {
            throw new Exception('El nombre no puede quedar vacío.');
        }
        if ($columna === 'codigo_barras') {
            // Se conserva como texto para no perder ceros iniciales. Admitimos
            // códigos 1D/2D alfanuméricos, pero nunca espacios ni caracteres
            // invisibles que después un lector no podría reproducir.
            if ($valor === '' || strlen($valor) > 80 || !preg_match('/^[\x21-\x7E]+$/', $valor)) {
                throw new Exception('El código debe tener hasta 80 caracteres visibles, sin espacios.');
            }
            $repetidoStmt = mysqli_prepare($conexion, 'SELECT id, nombre FROM productos WHERE codigo_barras = ? AND id <> ? LIMIT 1');
            if (!$repetidoStmt) {
                throw new Exception('No se pudo validar el código de barras.');
            }
            mysqli_stmt_bind_param($repetidoStmt, 'si', $valor, $id);
            mysqli_stmt_execute($repetidoStmt);
            $repetido = mysqli_fetch_assoc(mysqli_stmt_get_result($repetidoStmt));
            mysqli_stmt_close($repetidoStmt);
            if ($repetido) {
                throw new Exception('Ese código ya pertenece a “' . (string) $repetido['nombre'] . '” (ID ' . (int) $repetido['id'] . ').');
            }
        }
        $tipo = 'si';
    }

    $stmt = mysqli_prepare($conexion, "UPDATE productos SET `{$columna}` = ? WHERE id = ?");
    if (!$stmt) {
        throw new Exception('No se pudo preparar la actualización.');
    }
    if ($tipo === 'di') {
        mysqli_stmt_bind_param($stmt, 'di', $valor, $id);
    } elseif ($tipo === 'ii') {
        mysqli_stmt_bind_param($stmt, 'ii', $valor, $id);
    } else {
        mysqli_stmt_bind_param($stmt, 'si', $valor, $id);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('No se pudo guardar el cambio.');
    }
    nuviRegistrarAuditoria(
        $conexion,
        'producto_actualizado',
        'producto',
        $id,
        'Producto actualizado: ' . (string) $productoAnterior['nombre'] . ' (' . $columna . ')',
        ['campo' => $columna, 'anterior' => $productoAnterior['valor_anterior'], 'nuevo' => $valor]
    );
    responderInventario('ok', 'Actualizado.');
} catch (Throwable $e) {
    responderInventario('error', $e->getMessage(), 422);
}
?>
