<?php
header('Content-Type: application/json');
ob_start();

function responder_json($payload) {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('obtener_clientes json_encode falló: ' . json_last_error_msg());
        $json = json_encode(['error' => 'No se pudo serializar la respuesta']);
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
        header('Content-Type: application/json');
    }
    ob_clean();
    error_log('obtener_clientes.php fatal: ' . ($error['message'] ?? 'desconocido'));
    echo json_encode(['error' => 'No se pudo cargar la libreta']);
});

require_once('auth.php');
require_once("conexion.php"); // Usamos tu conexión estándar mysqli
require_once('libreta_utils.php');

if (!isset($conexion) || !$conexion) {
    responder_json(["error" => "Sin conexión a base de datos"]);
    exit;
}

try {
    $estadoSolicitado = strtolower(trim((string) ($_GET['estado'] ?? 'activos')));
    if (!in_array($estadoSolicitado, ['activos', 'archivados'], true)) {
        http_response_code(400);
        responder_json(['error' => 'Estado de clientes inválido']);
        exit;
    }

    $tablaClientes = mysqli_query($conexion, "SHOW TABLES LIKE 'clientes'");
    if (!$tablaClientes || mysqli_num_rows($tablaClientes) === 0) {
        throw new Exception("La tabla de clientes no existe en esta base");
    }

    $colsRes = mysqli_query($conexion, "SHOW COLUMNS FROM clientes");
    $cols = [];
    if ($colsRes) {
        while ($c = mysqli_fetch_assoc($colsRes)) {
            $cols[] = $c['Field'];
        }
    }

    $colId = in_array('id_cliente', $cols, true) ? 'id_cliente' : (in_array('id', $cols, true) ? 'id' : null);
    $colNombre = in_array('nombre_completo', $cols, true) ? 'nombre_completo' : (in_array('nombre', $cols, true) ? 'nombre' : null);
    $colTelefono = in_array('telefono', $cols, true) ? 'telefono' : (in_array('tel', $cols, true) ? 'tel' : null);
    $colSaldo = in_array('saldo_actual', $cols, true) ? 'saldo_actual' : (in_array('saldo', $cols, true) ? 'saldo' : null);
    $colLimite = in_array('limite_credito', $cols, true) ? 'limite_credito' : (in_array('limite', $cols, true) ? 'limite' : null);
    $colActivo = in_array('activo', $cols, true) ? 'activo' : null;

    if (!$colId || !$colNombre) {
        throw new Exception("La estructura de clientes no tiene columnas mínimas");
    }
    if ($estadoSolicitado === 'archivados' && !$colActivo) {
        throw new Exception('La libreta requiere actualización para consultar clientes archivados');
    }

    /*
     * Abrir la libreta no debe escribir ni recorrer movimientos por cliente.
     * El interés simple pendiente se calcula en una sola consulta y sólo se
     * registra al fiar, abonar o ajustar.
     */
    $tieneInicioInteres = in_array('fecha_inicio_interes', $cols, true);
    $tieneBaseInteres = in_array('saldo_base_interes', $cols, true);
    $tieneSemanasInteres = in_array('semanas_interes_aplicadas', $cols, true);
    $puedeCalcularInteres = $colSaldo && $tieneInicioInteres && $tieneBaseInteres && $tieneSemanasInteres;
    $saldoMostrado = $colSaldo ? "COALESCE(c.$colSaldo, 0)" : '0';
    $interesPendiente = '0';
    $semanasMora = '0';
    $porcentajeInteresPendiente = '0';

    if ($puedeCalcularInteres) {
        $semanasMora = "CASE
            WHEN COALESCE(c.$colSaldo, 0) > 0 AND c.fecha_inicio_interes IS NOT NULL
            THEN FLOOR(GREATEST(DATEDIFF(CURDATE(), c.fecha_inicio_interes), 0) / 7)
            ELSE 0
        END";
        $porcentajeTotal = "CASE WHEN ($semanasMora) <= 0 THEN 0 ELSE 5 + (GREATEST(($semanasMora) - 1, 0) * 10) END";
        $porcentajeAplicado = "CASE WHEN COALESCE(c.semanas_interes_aplicadas, 0) <= 0 THEN 0 ELSE 5 + (GREATEST(COALESCE(c.semanas_interes_aplicadas, 0) - 1, 0) * 10) END";
        $porcentajeInteresPendiente = "GREATEST(($porcentajeTotal) - ($porcentajeAplicado), 0)";
        $interesPendiente = "CASE
            WHEN COALESCE(c.$colSaldo, 0) > 0 AND COALESCE(c.saldo_base_interes, 0) > 0
            THEN ROUND(c.saldo_base_interes * ($porcentajeInteresPendiente) / 100, 2)
            ELSE 0
        END";
        $saldoMostrado = "ROUND(COALESCE(c.$colSaldo, 0) + ($interesPendiente), 2)";
    }

    $condicionEstado = '';
    if ($colActivo) {
        $condicionEstado = $estadoSolicitado === 'archivados'
            ? " WHERE c.$colActivo = 0"
            : " WHERE (c.$colActivo IS NULL OR c.$colActivo = 1)";
    }

    $sql = "SELECT
                c.$colId AS id_cliente,
                c.$colNombre AS nombre_completo,
                " . ($colTelefono ? "c.$colTelefono" : "''") . " AS telefono,
                $saldoMostrado AS saldo_actual,
                " . ($colSaldo ? "COALESCE(c.$colSaldo, 0)" : '0') . " AS saldo_registrado,
                $interesPendiente AS interes_pendiente,
                $semanasMora AS semanas_mora,
                $porcentajeInteresPendiente AS porcentaje_interes_pendiente,
                " . ($puedeCalcularInteres ? '1' : '0') . " AS interes_configurado,
                " . ($colLimite ? "COALESCE(c.$colLimite, 0)" : "0") . " AS limite_credito,
                " . ($colActivo ? "COALESCE(c.$colActivo, 1)" : "1") . " AS activo
            FROM clientes c" . $condicionEstado . "
            ORDER BY c.$colNombre ASC";
    $resultado = mysqli_query($conexion, $sql);

    if (!$resultado) {
        throw new Exception(mysqli_error($conexion));
    }

    $clientes = [];
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $clientes[] = $fila;
    }

    responder_json($clientes);
} catch (Throwable $e) {
    error_log('obtener_clientes.php: ' . $e->getMessage());
    responder_json(["error" => 'No se pudo cargar la libreta']);
}
?>
