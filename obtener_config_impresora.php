<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once('auth.php');
require_once('conexion.php');
require_once('impresion_ticket_utils.php');

$config = $conexion instanceof mysqli ? nuviObtenerConfigImpresora($conexion) : nuviImpresionConfigPredeterminada();
// Las cajas sólo necesitan saber si hay una térmica disponible. La IP queda
// limitada a administración y al servidor que la usa para imprimir.
echo json_encode([
    'success' => true,
    'activa' => (bool) $config['activa'],
    'nombre' => (string) $config['nombre'],
], JSON_UNESCAPED_UNICODE);
