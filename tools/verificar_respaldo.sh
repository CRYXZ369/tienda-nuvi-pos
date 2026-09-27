#!/usr/bin/env bash
# Verificación no destructiva del último respaldo de Nuvi.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="${NUVI_BACKUP_CONFIG:-$ROOT_DIR/config/backup.local.env}"
MAX_BACKUP_AGE_HOURS="${NUVI_MAX_BACKUP_AGE_HOURS:-36}"

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Falta $CONFIG_FILE. No se puede localizar el respaldo." >&2
    exit 2
fi

# shellcheck source=/dev/null
source "$CONFIG_FILE"

if [[ -z "${BACKUP_DIR:-}" || "$BACKUP_DIR" == "/" || ! -d "$BACKUP_DIR" ]]; then
    echo "BACKUP_DIR no existe o no es seguro." >&2
    exit 2
fi

for COMMAND in sha256sum stat; do
    command -v "$COMMAND" >/dev/null 2>&1 || { echo "No existe el comando $COMMAND" >&2; exit 3; }
done

DB_LATEST="$BACKUP_DIR/tienda_nuvi_db_LATEST.sql.gz"
WEB_LATEST="$BACKUP_DIR/tienda_nuvi_web_LATEST.tar.gz"
if [[ ! -s "$DB_LATEST" || ! -s "$WEB_LATEST" ]]; then
    echo "Falta el respaldo más reciente de base de datos o código." >&2
    exit 4
fi

shopt -s nullglob
MANIFESTS=("$BACKUP_DIR"/checksums_*.sha256)
if [[ ${#MANIFESTS[@]} -eq 0 ]]; then
    echo "No hay archivo de checksums para verificar." >&2
    exit 4
fi
CHECKSUM_FILE="${MANIFESTS[${#MANIFESTS[@]} - 1]}"

if ! (cd -- "$BACKUP_DIR" && sha256sum --check --status "$(basename "$CHECKSUM_FILE")"); then
    echo "El checksum del último respaldo no coincide." >&2
    exit 5
fi

FECHA_ARCHIVO="$(stat -c '%Y' -- "$DB_LATEST")"
AHORA="$(date +%s)"
EDAD_HORAS=$(( (AHORA - FECHA_ARCHIVO) / 3600 ))
if (( EDAD_HORAS > MAX_BACKUP_AGE_HOURS )); then
    echo "El último respaldo tiene ${EDAD_HORAS} h; el límite es ${MAX_BACKUP_AGE_HOURS} h." >&2
    exit 6
fi

echo "Respaldo válido: ${EDAD_HORAS} h de antigüedad · $(basename "$CHECKSUM_FILE")"
