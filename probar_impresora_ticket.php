<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once('auth.php');
tienePermiso(['admin']);
require_once('conexion.php');
require_once('auditoria_utils.php');
require_once('impresion_ticket_utils.php');

try {
    if (!$conexion instanceof mysqli) {
        throw new RuntimeException('No hay conexión con la base de datos.');
    }
    $config = nuviObtenerConfigImpresora($conexion);
    if (!$config['activa']) {
        throw new RuntimeException('Guarda primero la IP de la impresora y actívala.');
    }
    $datos = [
        'venta' => [
            'id_venta' => 0,
            'fecha_venta' => date('Y-m-d H:i:s'),
            'total' => 0,
            'metodo_pago' => 'efectivo',
            'nota_venta' => 'Prueba de conexion LAN.',
            'monto_cobrado_tarjeta' => null,
            'recargo_cliente_tarjeta' => null,
            'terminal_nombre' => '',
        ],
        'detalles' => [[
            'nombre' => 'Impresora conectada correctamente',
            'cantidad' => 1,
            'precio_unitario' => 0,
            'subtotal' => 0,
        ]],
    ];
    nuviEnviarTicketImpresora($config, nuviConstruirTicketEscPos($datos));
    nuviRegistrarAuditoria($conexion, 'prueba_impresora', 'impresora_ticket', null, 'Prueba de impresión enviada.', ['host' => $config['host'], 'puerto' => $config['puerto']]);
    echo json_encode(['success' => true, 'message' => 'Prueba enviada a ' . $config['nombre'] . '.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
