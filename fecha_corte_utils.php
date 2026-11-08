<?php

function nuviHoraBaseDatos(mysqli $conexion): string
{
    $resultado = mysqli_query($conexion, "SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS hora");
    $fila = $resultado ? mysqli_fetch_assoc($resultado) : null;
    if (!$fila || !is_string($fila['hora'] ?? null)) {
        throw new RuntimeException('No se pudo consultar la hora de la base de datos.');
    }
    return $fila['hora'];
}

function nuviFechaOperativaDesdeHora(string $hora): string
{
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $hora, new DateTimeZone('UTC'));
    $errores = DateTimeImmutable::getLastErrors();
    if (!$fecha || ($errores !== false && ($errores['warning_count'] || $errores['error_count']))
        || $fecha->format('Y-m-d H:i:s') !== $hora) {
        throw new InvalidArgumentException('La fecha y hora del corte no son válidas.');
    }
    if ((int) $fecha->format('H') < 6) {
        $fecha = $fecha->modify('-1 day');
    }
    return $fecha->format('Y-m-d');
}

function nuviFechaCorteValida($valor, string $hoy): ?string
{
    $texto = trim((string) $valor);
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto, new DateTimeZone('UTC'));
    $errores = DateTimeImmutable::getLastErrors();
    if (!$fecha || ($errores !== false && ($errores['warning_count'] || $errores['error_count']))
        || $fecha->format('Y-m-d') !== $texto || $texto > $hoy) {
        return null;
    }
    return $texto;
}
