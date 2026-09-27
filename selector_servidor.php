<?php
/**
 * REDIRECTOR AUTOMÁTICO DE SERVIDORES
 *
 * Detecta si el servidor principal está caído
 * Si lo está, redirige automáticamente al servidor de respaldo
 *
 * INSTRUCCIONES DE USO:
 * 1. Coloca este archivo en la raíz de Tienda
 * 2. Accede a: http://tuIP/Tienda/selector_servidor.php
 * 3. Se redirigirá automáticamente al servidor disponible
 */

require_once("config_servidor.php");

// Si se abrió desde la LAN, conservar esa ruta: una IP 100.x de Tailscale no
// sustituye una dirección LAN durante una caída del proveedor de Internet.
$hostActual = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$hostActual = preg_replace('/:\d+$/', '', $hostActual);
$principalDestino = in_array($hostActual, [SERVIDOR_PRINCIPAL_LAN, SERVIDOR_PRINCIPAL], true)
    ? $hostActual
    : SERVIDOR_PRINCIPAL;

// Verificar disponibilidad
$principal_ok = verificarServidorDisponible($principalDestino, 2);
$respaldo_ok = verificarServidorDisponible(SERVIDOR_RESPALDO, 2);

// Determinar a dónde redirigir
$destino = null;

if ($principal_ok) {
    $destino = "http://" . $principalDestino . RUTA_APLICACION . "/punto_de_venta.php";
    $servidor_nombre = $principalDestino === SERVIDOR_PRINCIPAL_LAN ? "Servidor Principal (LAN)" : "Servidor Principal";
} elseif ($respaldo_ok) {
    $destino = "http://" . SERVIDOR_RESPALDO . RUTA_APLICACION . "/punto_de_venta.php";
    $servidor_nombre = "Servidor de Respaldo";
} else {
    // Si ninguno funciona, intentar principal de todas formas
    $destino = "http://" . $principalDestino . RUTA_APLICACION . "/punto_de_venta.php";
    $servidor_nombre = "Principal (en transición)";
}

// Si está en JSON (para API)
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'destino' => $destino,
        'servidor' => $servidor_nombre,
        'principal_disponible' => $principal_ok,
        'respaldo_disponible' => $respaldo_ok,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Redirigir
header("Location: $destino");
exit;
?>
