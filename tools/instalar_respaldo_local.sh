#!/usr/bin/env bash
# Instala el respaldo diario en el disco interno del servidor.
# Para ejecutarlo una vez desde el servidor:
#   sudo /bin/bash /var/www/html/Tienda/tools/instalar_respaldo_local.sh
set -Eeuo pipefail

if (( EUID != 0 )); then
    echo 'Ejecuta este instalador con sudo en el servidor de Nuvi.' >&2
    exit 2
fi

DESTINO="${1:-/var/backups/tienda_nuvi}"
if [[ "$DESTINO" != /* || "$DESTINO" == / || "$DESTINO" =~ [[:space:]] ]]; then
    echo 'El destino debe ser una ruta absoluta, segura y sin espacios.' >&2
    exit 2
fi
case "$DESTINO" in
    /run/user/*/gvfs/*|/var/www/*)
        echo 'El respaldo local no puede ir a GVFS ni dentro de los archivos web.' >&2
        exit 2
        ;;
esac

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="$ROOT_DIR/config/backup.local.env"
CRON_FILE="/etc/cron.d/nuvi-backup"
SERVICE_FILE="/etc/systemd/system/nuvi-backup.service"
TIMER_FILE="/etc/systemd/system/nuvi-backup.timer"

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Falta $CONFIG_FILE. Copia config/backup.example.env y completa la conexión de MySQL." >&2
    exit 3
fi
if [[ ! -f "$ROOT_DIR/backup_diario.sh" || ! -f "$ROOT_DIR/tools/crear_respaldo.sh" ]]; then
    echo 'No se encontró una instalación completa de Nuvi.' >&2
    exit 3
fi

# El destino vive en el disco del servidor. Aún no sustituye la copia externa
# USB/NAS; sólo protege desde hoy contra borrados y fallos lógicos.
install -d -m 700 -o root -g root -- "$DESTINO"
if [[ ! -w "$DESTINO" ]]; then
    echo "No se puede escribir en $DESTINO" >&2
    exit 4
fi

MARCA="$(date +%Y%m%d_%H%M%S)"
COPIA_CONFIG="$DESTINO/backup.local.env.before-local-backup-$MARCA"
cp --preserve=mode,timestamps -- "$CONFIG_FILE" "$COPIA_CONFIG"
chmod 600 -- "$COPIA_CONFIG"

TEMP_CONFIG="$(mktemp "$ROOT_DIR/config/.backup.local.env.XXXXXX")"
cleanup() { rm -f -- "$TEMP_CONFIG"; }
trap cleanup EXIT

awk -v destino="$DESTINO" '
    BEGIN { reemplazado = 0 }
    /^BACKUP_DIR=/ { print "BACKUP_DIR=" destino; reemplazado = 1; next }
    { print }
    END { if (!reemplazado) print "BACKUP_DIR=" destino }
' "$CONFIG_FILE" > "$TEMP_CONFIG"
chmod 600 -- "$TEMP_CONFIG"
mv -- "$TEMP_CONFIG" "$CONFIG_FILE"

echo "Creando respaldo inicial en $DESTINO ..."
if ! /bin/bash "$ROOT_DIR/backup_diario.sh"; then
    cp --preserve=mode,timestamps -- "$COPIA_CONFIG" "$CONFIG_FILE"
    echo 'El respaldo inicial falló. Se restauró la configuración previa y no se programó el respaldo.' >&2
    exit 5
fi

cat > "$SERVICE_FILE" <<EOF
[Unit]
Description=Respaldo verificado de Miscelanea Nuvi
Wants=network-online.target
After=network-online.target mariadb.service mysql.service

[Service]
Type=oneshot
ExecStart=/bin/bash "$ROOT_DIR/backup_diario.sh"
EOF

cat > "$TIMER_FILE" <<'EOF'
[Unit]
Description=Respaldo diario persistente de Miscelanea Nuvi

[Timer]
OnCalendar=*-*-* 02:00:00
Persistent=true
AccuracySec=5m
Unit=nuvi-backup.service

[Install]
WantedBy=timers.target
EOF

# Cron pierde la tarea si el servidor estaba apagado. El timer persistente la
# recupera al arrancar; sólo se elimina la entrada antigua de Nuvi.
rm -f -- "$CRON_FILE"
systemctl daemon-reload
systemctl enable --now nuvi-backup.timer
systemctl is-enabled --quiet nuvi-backup.timer
systemctl is-active --quiet nuvi-backup.timer

echo 'Respaldo diario local instalado, comprobado y programado de forma persistente.'
echo "Destino: $DESTINO"
echo "Timer: nuvi-backup.timer (02:00; recupera una ejecución perdida al reiniciar)"
echo "Estado visible en Nuvi: Bitácora"
echo 'Cuando llegue la USB, usa instalar_respaldo_montado.sh para mover el destino a la copia externa.'
