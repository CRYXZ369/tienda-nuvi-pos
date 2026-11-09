<?php

function nuviCrearTokenAbastecimiento(string $tipo): string
{
    $token = bin2hex(random_bytes(32));
    if (!isset($_SESSION['nuvi_formularios_abastecimiento'][$tipo])) {
        $_SESSION['nuvi_formularios_abastecimiento'][$tipo] = [];
    }
    $_SESSION['nuvi_formularios_abastecimiento'][$tipo][$token] = true;
    if (count($_SESSION['nuvi_formularios_abastecimiento'][$tipo]) > 30) {
        array_shift($_SESSION['nuvi_formularios_abastecimiento'][$tipo]);
    }
    return $token;
}

function nuviConsumirTokenAbastecimiento(string $tipo, $token): bool
{
    if (!is_string($token) || !preg_match('/\A[0-9a-f]{64}\z/', $token)
        || !isset($_SESSION['nuvi_formularios_abastecimiento'][$tipo][$token])) {
        return false;
    }
    unset($_SESSION['nuvi_formularios_abastecimiento'][$tipo][$token]);
    return true;
}
