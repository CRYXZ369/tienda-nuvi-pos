<?php
require_once __DIR__ . '/../pedidos_proveedor_utils.php';

function comprobarPedido(bool $condicion, string $mensaje): void
{
    if (!$condicion) throw new RuntimeException($mensaje);
}

$lunes = new DateTimeImmutable('2026-11-09');
$calendario = nuviCoberturaPedido($lunes, [1, 3, 5], [2, 4, 6]);
comprobarPedido($calendario['pedido']->format('Y-m-d') === '2026-11-09', 'Preventa del lunes.');
comprobarPedido($calendario['entrega']->format('Y-m-d') === '2026-11-10', 'Entrega del martes.');
comprobarPedido($calendario['siguiente_entrega']->format('Y-m-d') === '2026-11-12', 'Siguiente entrega del jueves.');
comprobarPedido($calendario['dias'] === 4, 'Cobertura incluyendo hoy y siguiente entrega.');
$calendario = nuviCoberturaPedido(new DateTimeImmutable('2026-11-14'), [1, 3, 5], [2, 4, 6]);
comprobarPedido($calendario['pedido']->format('Y-m-d') === '2026-11-16', 'Cambio de semana.');
comprobarPedido(nuviCoberturaPedido($lunes, [], [2]) === null, 'Sin preventa no debe sugerir.');
$calendario = nuviCoberturaPedido($lunes, [1, 3], [5]);
comprobarPedido($calendario['entrega']->format('Y-m-d') === '2026-11-13'
    && $calendario['siguiente_entrega']->format('Y-m-d') === '2026-11-20', 'No repetir una misma entrega para dos preventas.');
comprobarPedido(nuviSugerenciaPedido(56, 28, 4, 5, null, 'pza') === 6.0, 'Sugerencia por piezas.');
comprobarPedido(nuviSugerenciaPedido(56, 28, -1, 5, 6, 'pza') === 12.0, 'Stock negativo y redondeo a paquete.');
comprobarPedido(nuviSugerenciaPedido(56, 28, 30, 5, 6, 'pza') === 0.0, 'Stock suficiente.');
comprobarPedido(nuviSugerenciaPedido(0, 28, -1, 5, null, 'pza') === null, 'Sin ventas debe revisarse.');
comprobarPedido(nuviSugerenciaPedido(1, 0, 0, 5, null, 'kg') === null, 'Sin historial completo.');
comprobarPedido(nuviSugerenciaPedido(1, 7, 0, 1, null, 'kg') === 0.143, 'Redondeo para granel.');
comprobarPedido(nuviDiasProveedor(['3', '1', '3']) === [1, 3], 'Normalización de días.');
try {
    nuviDiasProveedor(['8']);
    throw new RuntimeException('Día inválido aceptado.');
} catch (InvalidArgumentException $e) {
}
echo "OK: agenda semanal, cobertura, historial, stock y presentaciones.\n";
