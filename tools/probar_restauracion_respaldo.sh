#!/usr/bin/env bash
# Restaura el último dump en una base temporal y la elimina al terminar.
# Nunca usa la base de producción y exige un nombre explícito terminado en
# _restore_test para evitar una ejecución accidental sobre datos reales.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="${NUVI_BACKUP_CONFIG:-$ROOT_DIR/config/backup.local.env}"

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Falta $CONFIG_FILE." >&2
    exit 2
fi
# shellcheck source=/dev/null
source "$CONFIG_FILE"

for REQUIRED in DB_NAME DB_USER DB_PASSWORD BACKUP_DIR RESTORE_TEST_DB; do
    [[ -n "${!REQUIRED:-}" ]] || { echo "Falta $REQUIRED en $CONFIG_FILE." >&2; exit 2; }
done
if [[ "$RESTORE_TEST_DB" == "$DB_NAME" || ! "$RESTORE_TEST_DB" =~ ^[A-Za-z0-9_]+_restore_test$ ]]; then
    echo "RESTORE_TEST_DB debe ser una base distinta y terminar en _restore_test." >&2
    exit 2
fi

for COMMAND in mysql gzip; do
    command -v "$COMMAND" >/dev/null 2>&1 || { echo "No existe el comando $COMMAND." >&2; exit 3; }
done

DUMP="$BACKUP_DIR/tienda_nuvi_db_LATEST.sql.gz"
[[ -s "$DUMP" ]] || { echo "No existe un dump reciente para probar." >&2; exit 4; }

SQL_IDENT="\`${RESTORE_TEST_DB//\`/\`\`}\`"
limpiar() {
    MYSQL_PWD="$DB_PASSWORD" mysql -u "$DB_USER" -e "DROP DATABASE IF EXISTS $SQL_IDENT" >/dev/null 2>&1 || true
}
trap limpiar EXIT

MYSQL_PWD="$DB_PASSWORD" mysql -u "$DB_USER" -e "CREATE DATABASE $SQL_IDENT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gzip -cd -- "$DUMP" | MYSQL_PWD="$DB_PASSWORD" mysql -u "$DB_USER" "$RESTORE_TEST_DB"

TABLAS="$(MYSQL_PWD="$DB_PASSWORD" mysql -N -s -u "$DB_USER" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$RESTORE_TEST_DB'")"
if [[ ! "$TABLAS" =~ ^[1-9][0-9]*$ ]]; then
    echo "La restauración terminó sin tablas; se considera fallida." >&2
    exit 5
fi

printf 'Restauración de prueba correcta: %s tablas cargadas en %s (base temporal eliminada).\n' "$TABLAS" "$RESTORE_TEST_DB"
