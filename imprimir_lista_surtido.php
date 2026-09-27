<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once('auth.php');
require_once('conexion.php');
require_once('auditoria_utils.php');
require_once('impresion_ticket_utils.php');

try {
    if (!in_array($_SESSION['rol'] ?? '', ['admin', 'supervisor'], true)) {
        http_response_code(403);
        throw new RuntimeException('No tienes permiso para imprimir la lista de surtido.');
    }
    if (!$conexion instanceof mysqli) {
        throw new RuntimeException('No hay conexión con la base de datos.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new InvalidArgumentException('Método no permitido.');
    }
    $datos = json_decode(file_get_contents('php://input'), true);
    if (!is_array($datos) || !is_array($datos['lineas'] ?? null)) {
        throw new InvalidArgumentException('La lista de surtido no es válida.');
    }

    $lineas = [];
    foreach (array_slice($datos['lineas'], 0, 60) as $linea) {
        if (!is_array($linea)) {
            continue;
        }
        $cantidad = (float) ($linea['cantidad'] ?? 0);
        if (!is_finite($cantidad) || $cantidad <= 0 || $cantidad > 1000000) {
            continue;
        }
        $nombre = nuviImpresionTextoCorto((string) ($linea['nombre'] ?? ''), 120);
        if ($nombre === '') {
            continue;
        }
        $lineas[] = [
            'nombre' => $nombre,
            'marca' => nuviImpresionTextoCorto((string) ($linea['marca'] ?? ''), 80),
            'codigo' => nuviImpresionTextoCorto((string) ($linea['codigo'] ?? ''), 40),
            'unidad' => nuviImpresionTextoCorto((string) ($linea['unidad'] ?? 'unid.'), 20),
            'cantidad' => $cantidad,
        ];
    }
    if (!$lineas) {
        throw new InvalidArgumentException('No hay productos con cantidad sugerida para imprimir.');
    }

    $config = nuviObtenerConfigImpresora($conexion);
    if (!$config['activa']) {
        throw new RuntimeException('La impresora de red no está configurada.');
    }
    $opciones = [
        'dias_entrega' => (int) ($datos['dias_entrega'] ?? 0),
        'dias_seguridad' => (int) ($datos['dias_seguridad'] ?? 0),
    ];
    nuviEnviarTicketImpresora($config, nuviConstruirListaSurtidoEscPos($lineas, $opciones));
    nuviRegistrarAuditoria(
        $conexion,
        'lista_surtido_impresa',
        'inventario_critico',
        null,
        'Lista de surtido enviada a la térmica.',
        ['impresora' => $config['nombre'], 'productos' => count($lineas), 'dias_objetivo' => $opciones['dias_entrega'] + $opciones['dias_seguridad']]
    );
    echo json_encode(['success' => true, 'message' => 'Lista de surtido enviada a ' . $config['nombre'] . '.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
