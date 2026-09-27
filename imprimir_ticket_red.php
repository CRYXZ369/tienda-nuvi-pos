<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once('auth.php');
require_once('csrf_utils.php');
nuviRequerirCsrf();
require_once('conexion.php');
require_once('auditoria_utils.php');
require_once('impresion_ticket_utils.php');

try {
    if (!$conexion instanceof mysqli) {
        throw new RuntimeException('No hay conexión con la base de datos.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new InvalidArgumentException('Método no permitido.');
    }
    $datos = json_decode(file_get_contents('php://input'), true);
    $idVenta = (int) ($datos['id_venta'] ?? 0);
    $clave = trim((string) ($datos['clave_impresion'] ?? ''));
    // Una copia es una nueva orden de impresión del mismo comprobante ya
    // registrado. Nunca vuelve a pasar por finalizar_venta.php ni toca
    // inventario, pagos o libreta.
    $esCopia = (string) ($datos['tipo'] ?? '') === 'copia';
    if ($idVenta <= 0) {
        throw new InvalidArgumentException('No hay una venta confirmada para imprimir.');
    }
    $config = nuviObtenerConfigImpresora($conexion);
    if (!$config['activa']) {
        throw new RuntimeException('La impresora de red no está configurada.');
    }
    $solicitud = nuviImpresionRegistrarSolicitud($conexion, $idVenta, $clave, (int) ($_SESSION['id_usuario'] ?? 0));
    if (!$solicitud['nueva']) {
        if ($solicitud['estado'] === 'impreso') {
            echo json_encode([
                'success' => true,
                'ya_impreso' => true,
                'es_copia' => $esCopia,
                'message' => $esCopia
                    ? 'Esta copia ya se envió a la impresora.'
                    : 'Este ticket ya se envió a la impresora.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($solicitud['estado'] === 'error') {
            // La conexión pudo fallar porque estaba apagada o sin papel. No
            // reenviamos solos para no duplicar; el POS pide confirmación del
            // cajero y usa una clave nueva sólo si verificó que no salió.
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'puede_reintentar' => true,
                'message' => $esCopia
                    ? 'El intento anterior de la copia falló. Verifica que la copia no haya salido antes de reenviarla.'
                    : 'El intento anterior falló. Verifica que el ticket no haya salido antes de reenviarlo.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw new RuntimeException('La solicitud anterior no se puede confirmar. Revisa si la impresora ya sacó el ticket antes de volver a intentar.');
    }

    $ticket = nuviObtenerVentaParaTicket($conexion, $idVenta);
    nuviEnviarTicketImpresora($config, nuviConstruirTicketEscPos($ticket, ['copia' => $esCopia]));
    nuviImpresionMarcarSolicitud($conexion, $idVenta, $clave, 'impreso');
    nuviRegistrarAuditoria(
        $conexion,
        $esCopia ? 'ticket_copia_impresa' : 'ticket_impreso',
        'venta',
        $idVenta,
        $esCopia ? 'Copia de ticket enviada a impresora de red.' : 'Ticket enviado a impresora de red.',
        ['impresora' => $config['nombre'], 'tipo' => $esCopia ? 'copia' : 'original']
    );
    echo json_encode([
        'success' => true,
        'es_copia' => $esCopia,
        'message' => ($esCopia ? 'Copia del ticket enviada a ' : 'Ticket enviado a ') . $config['nombre'] . '.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (isset($idVenta, $clave) && $idVenta > 0 && $clave !== '' && isset($conexion) && $conexion instanceof mysqli) {
        nuviImpresionMarcarSolicitud($conexion, $idVenta, $clave, 'error');
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
