<?php

function nuviDiasProveedor($dias): array
{
    if (!is_array($dias)) throw new InvalidArgumentException('Días inválidos.');
    $validos = [];
    foreach ($dias as $dia) {
        if (!is_string($dia) || !preg_match('/^[1-7]$/D', $dia)) {
            throw new InvalidArgumentException('Días inválidos.');
        }
        $validos[(int) $dia] = (int) $dia;
    }
    sort($validos);
    return $validos;
}

function nuviProximoDia(DateTimeImmutable $desde, array $dias, bool $incluirHoy): ?DateTimeImmutable
{
    if (!$dias) return null;
    for ($i = $incluirHoy ? 0 : 1; $i <= 7; $i++) {
        $fecha = $desde->modify("+{$i} days");
        if (in_array((int) $fecha->format('N'), $dias, true)) return $fecha;
    }
    return null;
}

function nuviCoberturaPedido(DateTimeImmutable $hoy, array $preventa, array $entrega): ?array
{
    $pedido = nuviProximoDia($hoy, $preventa, true);
    if (!$pedido || !$entrega) return null;
    $primeraEntrega = nuviProximoDia($pedido, $entrega, true);
    $siguientePedido = nuviProximoDia($pedido, $preventa, false);
    $siguienteEntrega = nuviProximoDia($siguientePedido, $entrega, true);
    if ($siguienteEntrega <= $primeraEntrega) {
        $siguienteEntrega = nuviProximoDia($primeraEntrega, $entrega, false);
    }
    return [
        'pedido' => $pedido,
        'entrega' => $primeraEntrega,
        'siguiente_entrega' => $siguienteEntrega,
        'dias' => (int) $hoy->diff($siguienteEntrega)->days + 1,
    ];
}

function nuviSugerenciaPedido(float $ventas, int $diasHistorial, float $stock, int $diasCobertura, ?float $paquete, string $unidad): ?float
{
    if ($ventas <= 0 || $diasHistorial <= 0 || $diasCobertura <= 0) return null;
    $faltante = max(0, $ventas / $diasHistorial * $diasCobertura - $stock);
    $paso = $paquete ?? (strtolower(trim($unidad)) === 'pza' ? 1.0 : 0.001);
    return round(ceil(($faltante - 1e-10) / $paso) * $paso, 3);
}
