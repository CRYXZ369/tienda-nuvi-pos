#!/usr/bin/env bash
# Instala respaldo diario de Nuvi en un USB/NAS ya montado de forma permanente.
# Se ejecuta UNA vez desde la terminal DEL SERVIDOR como root:
#   sudo /bin/bash tools/instalar_respaldo_montado.sh /mnt/nuvi-backups
set -Eeuo pipefail

if (( EUID != 0 )); then
    echo 'Ejecuta este instalador con sudo en el servidor de Nuvi.' >&2
    exit 2
fi

DESTINO="${1:-}"
if [[ -z "$DESTINO" || "$DESTINO" != /* || "$DESTINO" == / || "$DESTINO" =~ [[:space:]] ]]; then
    echo 'Indica una ruta absoluta, segura y sin espacios; ejemplo: /mnt/nuvi-backups' >&2
    exit 2
fi
case "$DESTINO" in
    /run/user/*/gvfs/*)
        echo 'No uses una ruta GVFS: depende de una sesión gráfica y cron no la verá.' >&2
        exit 2
        ;;
esac

if ! command -v mountpoint >/dev/null 2>&1 || ! mountpoint -q -- "$DESTINO"; then
    echo "El destino no es un punto de montaje activo: $DESTINO" >&2
    echo 'Monta primero el USB/NAS de forma permanente (fstab o systemd mount) y vuelve a ejecutar.' >&2
    exit 3
fi
if [[ ! -w "$DESTINO" ]]; then
    echo "No se puede escribir en el destino: $DESTINO" >&2
    exit 3
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="$ROOT_DIR/config/backup.local.env"
CRON_FILE="/etc/cron.d/nuvi-backup"
SERVICE_FILE="/etc/systemd/system/nuvi-backup.service"
TIMER_FILE="/etc/systemd/system/nuvi-backup.timer"

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Falta $CONFIG_FILE. Cópialo desde config/backup.example.env y completa los datos de la base." >&2
    exit 4
fi
if [[ ! -x /bin/bash || ! -f "$ROOT_DIR/backup_diario.sh" ]]; then
    echo 'No se encontró la instalación de Nuvi esperada.' >&2
    exit 4
fi

# El cron se instala sólo después de que un respaldo manual valide el destino.
MARCA="$(date +%Y%m%d_%H%M%S)"
RESPALDO_CONFIG="$CONFIG_FILE.before-mounted-backup-$MARCA"
cp --preserve=mode,timestamps -- "$CONFIG_FILE" "$RESPALDO_CONFIG"
chmod 600 -- "$RESPALDO_CONFIG"

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

echo "Probando respaldo inicial en $DESTINO ..."
if ! /bin/bash "$ROOT_DIR/backup_diario.sh"; then
    cp --preserve=mode,timestamps -- "$RESPALDO_CONFIG" "$CONFIG_FILE"
    echo 'El respaldo inicial falló. Se restauró la configuración anterior y no se programó el respaldo.' >&2
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

rm -f -- "$CRON_FILE"
systemctl daemon-reload
systemctl enable --now nuvi-backup.timer
systemctl is-enabled --quiet nuvi-backup.timer
systemctl is-active --quiet nuvi-backup.timer

echo 'Respaldo inicial correcto y timer persistente instalado.'
echo "Destino: $DESTINO"
echo "Timer: nuvi-backup.timer (02:00; recupera una ejecución perdida al reiniciar)"
echo 'Conserva la copia de configuración previa hasta comprobar dos respaldos diarios.'
