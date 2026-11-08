#!/usr/bin/env bash
set -Eeuo pipefail

raiz="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
temporal="$(mktemp -d)"
origen="$temporal/origen"
destino="$temporal/destino"
cleanup() {
    rm -f -- "$origen/app.php" "$origen/.env.local" "$origen/tienda_nuvi.backup.sql" \
        "$origen/config/local.php" "$origen/config/local.example.php" \
        "$origen/backups/copia.sql" "$origen/logs/error.log" \
        "$destino/app.php" "$destino/config/local.example.php" "$temporal/error"
    rmdir -- "$origen/config" "$origen/backups" "$origen/logs" "$origen/hijo" \
        "$destino/config" "$destino/hijo" "$origen" "$destino" "$temporal"
}
trap cleanup EXIT

mkdir -p "$origen/config" "$origen/backups" "$origen/logs" "$origen/hijo" "$destino"
printf 'aplicacion' > "$origen/app.php"
printf 'ejemplo' > "$origen/config/local.example.php"
printf 'privado' > "$origen/config/local.php"
printf 'privado' > "$origen/.env.local"
printf 'privado' > "$origen/tienda_nuvi.backup.sql"
printf 'privado' > "$origen/backups/copia.sql"
printf 'privado' > "$origen/logs/error.log"

bash "$raiz/tools/copiar_archivos_instalacion.sh" "$origen" "$destino"
[[ "$(cat "$destino/app.php")" == aplicacion ]]
[[ "$(cat "$destino/config/local.example.php")" == ejemplo ]]
for excluido in config/local.php .env.local tienda_nuvi.backup.sql backups logs; do
    [[ ! -e "$destino/$excluido" ]]
done

if bash "$raiz/tools/copiar_archivos_instalacion.sh" "$origen" "$origen/hijo" 2>"$temporal/error"; then
    printf 'Se aceptó un destino dentro del origen.\n' >&2
    exit 1
fi
if bash "$raiz/tools/copiar_archivos_instalacion.sh" "$origen" "$destino" 2>"$temporal/error"; then
    printf 'Se aceptó un destino que ya contenía archivos.\n' >&2
    exit 1
fi
printf 'OK: copia de instalación y exclusiones\n'
