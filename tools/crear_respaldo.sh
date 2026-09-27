#!/usr/bin/env bash
# Respaldo consistente de Nuvi: base de datos + código, apto para cron.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="${NUVI_BACKUP_CONFIG:-$ROOT_DIR/config/backup.local.env}"
UPLOAD_REMOTE="${NUVI_UPLOAD_REMOTE:-0}"

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Falta $CONFIG_FILE. Copia config/backup.example.env como backup.local.env." >&2
    exit 2
fi

# shellcheck source=/dev/null
source "$CONFIG_FILE"

for REQUIRED in DB_NAME DB_USER DB_PASSWORD WEB_DIR BACKUP_DIR; do
    if [[ -z "${!REQUIRED:-}" ]]; then
        echo "Falta $REQUIRED en $CONFIG_FILE" >&2
        exit 2
    fi
done

if [[ "$BACKUP_DIR" == "/" || -z "$BACKUP_DIR" ]]; then
    echo "BACKUP_DIR no es seguro." >&2
    exit 2
fi

for COMMAND in mysqldump tar gzip sha256sum; do
    command -v "$COMMAND" >/dev/null 2>&1 || { echo "No existe el comando $COMMAND" >&2; exit 3; }
done

mkdir -p -- "$BACKUP_DIR"
umask 077

if command -v flock >/dev/null 2>&1; then
    exec 9>"$BACKUP_DIR/.nuvi-backup.lock"
    flock -n 9 || { echo "Ya hay un respaldo en ejecución." >&2; exit 4; }
fi

TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/nuvi-backup.XXXXXX")"
cleanup() { rm -r -- "$TEMP_DIR"; }
trap cleanup EXIT

STAMP="$(date +"%Y-%m-%d_%H%M%S")"
DB_FINAL="$BACKUP_DIR/tienda_nuvi_db_${STAMP}.sql.gz"
WEB_FINAL="$BACKUP_DIR/tienda_nuvi_web_${STAMP}.tar.gz"
MANIFEST="$BACKUP_DIR/backup_manifest.log"

DB_TEMP="$TEMP_DIR/tienda_nuvi.sql"
WEB_TEMP="$TEMP_DIR/tienda_nuvi_web.tar.gz"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$MANIFEST"; }

log "Iniciando respaldo consistente"
MYSQL_PWD="$DB_PASSWORD" mysqldump \
    --single-transaction --quick --routines --events --no-tablespaces \
    -u "$DB_USER" "$DB_NAME" > "$DB_TEMP"
gzip -c "$DB_TEMP" > "$DB_FINAL.tmp"
mv -- "$DB_FINAL.tmp" "$DB_FINAL"
gzip -t -- "$DB_FINAL"
log "Base de datos creada y verificada: $(basename "$DB_FINAL")"

tar -czf "$WEB_TEMP" -C "$WEB_DIR" \
    --exclude='./backups' \
    --exclude='./.git' \
    --exclude='./recursos' \
    --exclude='./config/local.php' \
    --exclude='./config/backup.local.env*' \
    .
mv -- "$WEB_TEMP" "$WEB_FINAL"
tar -tzf "$WEB_FINAL" >/dev/null
log "Código creado y verificado: $(basename "$WEB_FINAL")"

(
    cd -- "$BACKUP_DIR"
    sha256sum "$(basename "$DB_FINAL")" "$(basename "$WEB_FINAL")" > "checksums_${STAMP}.sha256"
    sha256sum --check --status "checksums_${STAMP}.sha256"
)
log "Checksums generados y verificados: checksums_${STAMP}.sha256"

RETENTION_DAYS="${RETENTION_DAYS:-28}"
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'tienda_nuvi_db_*.sql.gz' -o -name 'tienda_nuvi_web_*.tar.gz' -o -name 'checksums_*.sha256' \) -mtime +"$RETENTION_DAYS" -delete

ln -sfn "$(basename "$DB_FINAL")" "$BACKUP_DIR/tienda_nuvi_db_LATEST.sql.gz"
ln -sfn "$(basename "$WEB_FINAL")" "$BACKUP_DIR/tienda_nuvi_web_LATEST.tar.gz"

if [[ "$UPLOAD_REMOTE" == "1" ]]; then
    for REQUIRED in LAPTOP_HOST LAPTOP_USER LAPTOP_DESTINATION; do
        if [[ -z "${!REQUIRED:-}" ]]; then
            echo "Falta $REQUIRED para el respaldo remoto." >&2
            exit 5
        fi
    done
    command -v scp >/dev/null 2>&1 || { echo "No existe scp." >&2; exit 5; }
    ARCHIVO_REMOTO="$TEMP_DIR/nuvi_respaldo_${STAMP}.tar.gz"
    tar -czf "$ARCHIVO_REMOTO" -C "$BACKUP_DIR" "$(basename "$DB_FINAL")" "$(basename "$WEB_FINAL")" "checksums_${STAMP}.sha256"
    scp -o BatchMode=yes -o ConnectTimeout=15 "$ARCHIVO_REMOTO" "$LAPTOP_USER@$LAPTOP_HOST:$LAPTOP_DESTINATION"
    log "Copia remota enviada a $LAPTOP_HOST"
fi

log "RESPALDO COMPLETADO"
printf 'Respaldo terminado: %s\n' "$STAMP"
