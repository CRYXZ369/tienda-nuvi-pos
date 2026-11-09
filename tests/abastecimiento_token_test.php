<?php
require_once __DIR__ . '/../abastecimiento_form_utils.php';

$_SESSION = [];
$compra = nuviCrearTokenAbastecimiento('compra');
$merma = nuviCrearTokenAbastecimiento('merma');
if (nuviConsumirTokenAbastecimiento('merma', $compra)
    || nuviConsumirTokenAbastecimiento('compra', ['token'])
    || !nuviConsumirTokenAbastecimiento('compra', $compra)
    || nuviConsumirTokenAbastecimiento('compra', $compra)
    || !nuviConsumirTokenAbastecimiento('merma', $merma)) {
    throw new RuntimeException('Falló la protección de formularios de abastecimiento.');
}

$primero = nuviCrearTokenAbastecimiento('compra');
for ($i = 0; $i < 30; $i++) {
    $ultimo = nuviCrearTokenAbastecimiento('compra');
}
if (nuviConsumirTokenAbastecimiento('compra', $primero)
    || !nuviConsumirTokenAbastecimiento('compra', $ultimo)) {
    throw new RuntimeException('No se respetó el límite de formularios pendientes.');
}
echo "OK: formularios independientes y de un solo uso.\n";
