<?php

function nuviFechaTabletEnNota(string $nota): string
{
    $prefijo = 'Venta de emergencia registrada en tablet: ';
    if (!str_starts_with($nota, $prefijo)) {
        return '';
    }
    $valor = substr($nota, strlen($prefijo), 24);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $valor)) {
        return '';
    }
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $valor, new DateTimeZone('UTC'));
    $errores = DateTimeImmutable::getLastErrors();
    if (!$fecha || ($errores !== false && ($errores['warning_count'] || $errores['error_count']))
        || $fecha->format('Y-m-d\TH:i:s.v\Z') !== $valor) {
        return '';
    }
    return $valor;
}

function nuviFilaReporteEmergencia(array $fila): array
{
    $fila['Hora tablet (UTC, sin verificar)'] = nuviFechaTabletEnNota((string) ($fila['Nota'] ?? ''));
    $fila['Estado hora tablet'] = $fila['Hora tablet (UTC, sin verificar)'] === ''
        ? 'No disponible: revisar nota y tablet'
        : 'Sin verificar: comprobar reloj de tablet y corte';
    return $fila;
}
