#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 2 || ! -d "$1" || ! -d "$2" ]]; then
    printf 'Uso: bash copiar_archivos_instalacion.sh ORIGEN DESTINO\n' >&2
    exit 1
fi

origen="$(realpath -e -- "$1")"
destino="$(realpath -e -- "$2")"
if [[ "$origen" == / || "$destino" == "$origen" || "$destino" == "$origen/"* ]]; then
    printf 'El destino no puede estar dentro del paquete de instalación.\n' >&2
    exit 1
fi
if find "$destino" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
    printf 'La carpeta destino no está vacía: %s\n' "$destino" >&2
    exit 1
fi

tar -C "$origen" \
    --exclude='./.git' \
    --exclude='./dist' \
    --exclude='./backups' \
    --exclude='./logs' \
    --exclude='./releases' \
    --exclude='./recursos' \
    --exclude='./RESPALDO UBUNTU' \
    --exclude='./documentacion/historico/backup_innecesarios_2026-04-22' \
    --exclude='./config/local.php' \
    --exclude='./config/backup.local.env' \
    --exclude='./config/ticket.local.php' \
    --exclude='./.env*' \
    --exclude='./tienda_nuvi.backup.sql' \
    -cf - . | tar -C "$destino" -xf -
