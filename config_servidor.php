<?php
/** Configuración de ejemplo del selector opcional de servidores. */
const SERVIDOR_PRINCIPAL_LAN = '127.0.0.1';
const SERVIDOR_PRINCIPAL = '127.0.0.1';
const SERVIDOR_RESPALDO = '127.0.0.2';
const RUTA_APLICACION = '/Tienda';
const PUERTO_HTTP = 80;

function verificarServidorDisponible(string $host, int $timeoutSegundos = 2): bool
{
    $errno = 0;
    $error = '';
    $timeoutSegundos = max(1, $timeoutSegundos);
    $socket = @fsockopen($host, PUERTO_HTTP, $errno, $error, $timeoutSegundos);
    if (!$socket) {
        return false;
    }

    stream_set_timeout($socket, $timeoutSegundos);
    $ruta = RUTA_APLICACION . '/health_check.php';
    $peticion = "GET {$ruta} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n";
    fwrite($socket, $peticion);
    $respuesta = stream_get_contents($socket);
    fclose($socket);

    return preg_match('/^HTTP\/\d\.\d 200/', $respuesta) === 1
        && preg_match('/\r?\n\r?\nOK\s*$/', $respuesta) === 1;
}
?>
