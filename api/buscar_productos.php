<?php
header('Content-Type: application/json; charset=utf-8');
ob_start();
require_once __DIR__ . '/../auth.php';

function responder_json($payload) {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('buscar_productos json_encode falló: ' . json_last_error_msg());
        $json = json_encode(['status' => 'error', 'results' => [], 'message' => 'No se pudo serializar la respuesta']);
    }
    ob_clean();
    echo $json;
}

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) return;
    if (headers_sent() === false) {
        header('Content-Type: application/json; charset=utf-8');
    }
    ob_clean();
    echo json_encode([
        'status' => 'error',
        'results' => [],
        'message' => 'No se pudo completar la búsqueda'
    ]);
});

include __DIR__ . '/../conexion.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] == '1' ? true : false;
try {
    if (!isset($conexion) || !$conexion) {
        throw new Exception('Sin conexión a base de datos');
    }

    if (function_exists('mysqli_set_charset')) {
        $conexion->set_charset('utf8mb4');
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $productos = [];

    $tieneActivo = false;
    $colActivo = mysqli_query($conexion, "SHOW COLUMNS FROM productos LIKE 'activo'");
    if ($colActivo && mysqli_num_rows($colActivo) > 0) {
        $tieneActivo = true;
    }

    $camposSelect = "id, nombre, codigo_barras, marca, precio_venta, precio_compra, stock, categoria, unidad_medida";
    if ($tieneActivo) {
        $camposSelect .= ", activo";
    }

    if ($q === '') {
        $sql = "SELECT $camposSelect FROM productos";
        if (!$includeInactive && $tieneActivo) {
            $sql .= " WHERE (activo IS NULL OR activo = 1)";
        }
        $sql .= " ORDER BY stock ASC, id ASC";
    } else {
        if (strlen($q) < 2) {
            responder_json(['status' => 'ok', 'results' => []]);
            exit;
        }

        // Cada palabra se busca por separado. Así "leche alpura 1l" encuentra
        // lo mismo que "Alpura leche 1L", sin interpolar texto del usuario en SQL.
        $palabras = array_values(array_filter(
            preg_split('/\s+/u', $q),
            static function ($palabra) { return $palabra !== ''; }
        ));
        $palabras = array_slice($palabras, 0, 6);

        if (!$palabras) {
            responder_json(['status' => 'ok', 'results' => []]);
            exit;
        }

        $condiciones = [];
        $tipos = '';
        $valores = [];
        foreach ($palabras as $palabra) {
            $condiciones[] = '(nombre LIKE ? OR codigo_barras LIKE ? OR marca LIKE ? OR categoria LIKE ?)';
            $likePalabra = '%' . $palabra . '%';
            for ($i = 0; $i < 4; $i++) {
                $tipos .= 's';
                $valores[] = $likePalabra;
            }
        }

        // Un ID exacto sigue funcionando, incluso si es numérico y no forma
        // parte de ningún nombre o código de barras.
        $idSearch = ctype_digit($q) ? (int) $q : 0;
        $tipos .= 'i';
        $valores[] = $idSearch;

        $sql = "SELECT $camposSelect FROM productos WHERE ((" . implode(' AND ', $condiciones) . ") OR id = ?)";
        if (!$includeInactive && $tieneActivo) {
            $sql .= " AND (activo IS NULL OR activo = 1)";
        }
        $sql .= " ORDER BY stock ASC, id ASC LIMIT 50";
    }

    if ($stmt = $conexion->prepare($sql)) {
        if ($q !== '') {
            // bind_param requiere referencias; se construyen sin exponer datos
            // al SQL y funciona con una cantidad variable de palabras.
            $referencias = [$tipos];
            foreach ($valores as $indice => $valor) {
                $referencias[] = &$valores[$indice];
            }
            call_user_func_array([$stmt, 'bind_param'], $referencias);
        }
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $productos[] = $row;
            }
        }
        $stmt->close();
    } else {
        throw new Exception($conexion->error ?: 'Error preparando búsqueda de productos');
    }

    responder_json(['status' => 'ok', 'results' => $productos]);
} catch (Throwable $e) {
    error_log('api/buscar_productos.php: ' . $e->getMessage());
    responder_json(['status' => 'error', 'results' => [], 'message' => 'No se pudo completar la búsqueda']);
}
?>
