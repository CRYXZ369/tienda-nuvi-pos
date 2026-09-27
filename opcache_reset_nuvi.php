<?php
header('Content-Type: application/json; charset=utf-8');
require_once('auth.php');
tienePermiso(['admin']);
$ok = false;
if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
}
echo json_encode([
    'opcache_reset_available' => function_exists('opcache_reset'),
    'reset_result' => $ok,
    'time' => date('c')
]);
