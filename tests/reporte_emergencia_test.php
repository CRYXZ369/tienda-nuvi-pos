<?php
require_once __DIR__ . '/../reporte_emergencia_utils.php';

$prefijo = 'Venta de emergencia registrada en tablet: ';
$valida = '2026-11-07T23:59:59.123Z';
foreach ([$prefijo . $valida, $prefijo . $valida . ' [Venta revisada]'] as $nota) {
    if (nuviFechaTabletEnNota($nota) !== $valida) {
        throw new RuntimeException('No se conservó la hora UTC de la tablet.');
    }
}
foreach (['', 'Nota cualquiera', $prefijo . '2026-02-30T12:00:00.000Z',
    $prefijo . '2026-11-07T23:59:59Z', $prefijo . '2026-11-07T23:59:59.123+06:00'] as $nota) {
    if (nuviFechaTabletEnNota($nota) !== '') {
        throw new RuntimeException('Se aceptó una hora inexistente o sin formato de tablet.');
    }
}
$fila = nuviFilaReporteEmergencia(['Nota' => $prefijo . $valida, 'Venta' => 42]);
if ($fila['Venta'] !== 42 || $fila['Hora tablet (UTC, sin verificar)'] !== $valida
    || !str_contains($fila['Estado hora tablet'], 'Sin verificar')) {
    throw new RuntimeException('El reporte debe conservar la venta y advertir que el reloj no está verificado.');
}
$fila = nuviFilaReporteEmergencia(['Nota' => 'sin fecha']);
if ($fila['Hora tablet (UTC, sin verificar)'] !== '' || !str_contains($fila['Estado hora tablet'], 'No disponible')) {
    throw new RuntimeException('Una fecha ausente debe señalarse explícitamente.');
}
echo "OK: hora de tablet, nota revisada y advertencias.\n";
