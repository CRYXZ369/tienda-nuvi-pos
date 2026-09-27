<?php
require_once('auth.php');
tienePermiso(['admin']);

$certificado = '/etc/ssl/nuvi-local-ca/nuvi-local-ca.crt';
if (!is_file($certificado) || !is_readable($certificado)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'El certificado local de Nuvi aún no está preparado.';
    exit;
}

header('Content-Type: application/x-x509-ca-cert');
header('Content-Disposition: attachment; filename="nuvi-local-ca.crt"');
header('Content-Length: ' . (string) filesize($certificado));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile($certificado);
exit;
