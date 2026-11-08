<?php
require_once __DIR__ . '/../fecha_corte_utils.php';

foreach (['UTC', 'America/Mexico_City', 'Pacific/Auckland'] as $zona) {
    date_default_timezone_set($zona);
    $casos = [
        '2026-01-01 05:59:59' => '2025-12-31',
        '2026-01-01 06:00:00' => '2026-01-01',
        '2026-02-28 23:59:59' => '2026-02-28',
        '2024-03-01 05:59:59' => '2024-02-29',
    ];
    foreach ($casos as $hora => $esperada) {
        if (nuviFechaOperativaDesdeHora($hora) !== $esperada) {
            throw new RuntimeException("Día operativo incorrecto para $hora en $zona.");
        }
    }
    foreach (['2026-02-30', '2026-03-02', '', '2026-2-1'] as $invalida) {
        if (nuviFechaCorteValida($invalida, '2026-03-01') !== null) {
            throw new RuntimeException("Se aceptó la fecha inválida $invalida.");
        }
    }
    if (nuviFechaCorteValida('2026-03-01', '2026-03-01') !== '2026-03-01') {
        throw new RuntimeException('Se rechazó la fecha actual de la base de datos.');
    }
}

try {
    nuviFechaOperativaDesdeHora('2026-02-30 06:00:00');
    throw new RuntimeException('Se aceptó una hora inválida.');
} catch (InvalidArgumentException $error) {
    // La hora mal formada debe rechazarse, no corregirse automáticamente.
}
echo "OK: fecha operativa y validación independientes de la zona horaria de PHP.\n";
