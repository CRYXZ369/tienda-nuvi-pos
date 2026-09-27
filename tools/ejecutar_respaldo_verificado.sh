#!/usr/bin/env bash
# Ejecuta respaldo + verificación y deja un estado legible para operación.
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="${NUVI_BACKUP_CONFIG:-$ROOT_DIR/config/backup.local.env}"
STATUS_DIR="$ROOT_DIR/logs"
STATUS_FILE="$STATUS_DIR/estado_respaldo.json"

mkdir -p -- "$STATUS_DIR"
# El estado sólo contiene fecha y resultado, no contraseñas ni contenido de la
# base. Debe poder leerse desde la pantalla administrativa que sirve Apache.
chmod 755 -- "$STATUS_DIR" 2>/dev/null || true
umask 077

json_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e ':a;N;$!ba;s/\n/\\n/g'
}

guardar_estado() {
    local estado="$1"
    local mensaje="$2"
    local temporal
    temporal="$(mktemp "$STATUS_DIR/.estado_respaldo.XXXXXX")"
    printf '{"estado":"%s","fecha":"%s","mensaje":"%s"}\n' \
        "$estado" "$(date --iso-8601=seconds)" "$(json_escape "$mensaje")" > "$temporal"
    chmod 644 -- "$temporal"
    mv -- "$temporal" "$STATUS_FILE"
}

notificar_fallo() {
    local mensaje="$1"
    [[ -f "$CONFIG_FILE" ]] || return 0
    # shellcheck source=/dev/null
    source "$CONFIG_FILE"
    [[ -n "${BACKUP_ALERT_WEBHOOK_URL:-}" ]] || return 0
    command -v curl >/dev/null 2>&1 || return 0

    local cuerpo
    cuerpo="{\"text\":\"Nuvi: $(json_escape "$mensaje")\"}"
    curl --fail --silent --show-error --max-time 15 \
        -H 'Content-Type: application/json' \
        --data "$cuerpo" "$BACKUP_ALERT_WEBHOOK_URL" \
        || printf '[%s] No se pudo enviar la alerta externa de respaldo.\n' "$(date '+%F %T')" >&2
}

resultado=0
bash "$SCRIPT_DIR/crear_respaldo.sh" || resultado=$?
if (( resultado == 0 )); then
    bash "$SCRIPT_DIR/verificar_respaldo.sh" || resultado=$?
fi

if (( resultado == 0 )); then
    mensaje="Respaldo y verificación completados correctamente."
    guardar_estado "correcto" "$mensaje"
    printf '%s\n' "$mensaje"
    exit 0
fi

mensaje="Falló el respaldo o su verificación (código $resultado). Revisa $STATUS_FILE y backup_manifest.log."
guardar_estado "error" "$mensaje"
notificar_fallo "$mensaje"
printf '%s\n' "$mensaje" >&2
exit "$resultado"
