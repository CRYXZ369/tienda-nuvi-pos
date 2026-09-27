<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once('auth.php');
require_once("conexion.php");
require_once("comisiones_terminal.php");

if (!isset($conexion) || !$conexion) {
    $config = nuviConfigComisionPredeterminada();
    $config['configuracion_lista'] = false;
    echo json_encode($config);
    exit;
}

$terminales = nuviObtenerTerminalesPago($conexion);
$config = $terminales[0] ?? nuviObtenerConfigComision($conexion);
$config['terminales'] = $terminales;
$config['id_terminal_predeterminada'] = (int) ($terminales[0]['id'] ?? 0);
$config['configuracion_lista'] = nuviEsquemaComisionesListo($conexion) && count($terminales) > 0;
echo json_encode($config, JSON_UNESCAPED_UNICODE);
?>
