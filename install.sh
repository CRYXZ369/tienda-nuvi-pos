#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${NUVI_INSTALL_DIR:-/var/www/html/tienda-nuvi}"
DB_NAME="${NUVI_DB_NAME:-tienda_nuvi}"
DISTRO=''
HTTPD_SERVICE=''
WEB_GROUP=''

if [[ "$EUID" -ne 0 ]]; then
    printf 'Ejecuta el instalador como administrador: sudo bash %s\n' "$0" >&2
    exit 1
fi

if [[ ! -f "$SCRIPT_DIR/database/schema.sql" || ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ || ! "$APP_DIR" =~ ^/[A-Za-z0-9/_-]+$ ]]; then
    printf 'La ruta de instalación, el nombre de base o el paquete no son válidos.\n' >&2
    exit 1
fi

APP_DIR_RESUELTO="$(realpath -m -- "$APP_DIR")"
if [[ "$APP_DIR_RESUELTO" == "$SCRIPT_DIR" || "$APP_DIR_RESUELTO" == "$SCRIPT_DIR/"* ]]; then
    printf 'La carpeta destino no puede estar dentro del paquete de instalación.\n' >&2
    exit 1
fi
if [[ -e "$APP_DIR" && ! -d "$APP_DIR" ]]; then
    printf 'La ruta de destino no es una carpeta: %s\n' "$APP_DIR" >&2
    exit 1
fi
if [[ -e "$APP_DIR" ]] && find "$APP_DIR" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
    printf 'La carpeta destino ya existe y no está vacía: %s\n' "$APP_DIR" >&2
    exit 1
fi

if command -v apt-get >/dev/null 2>&1; then
    DISTRO='debian'
    HTTPD_SERVICE='apache2'
    WEB_GROUP='www-data'
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 mariadb-server php-cli php-mysql php-mbstring php-xml libapache2-mod-php openssl
elif command -v dnf >/dev/null 2>&1; then
    DISTRO='fedora'
    HTTPD_SERVICE='httpd'
    WEB_GROUP='apache'
    dnf install -y httpd mariadb-server php-cli php-fpm php-mysqlnd php-mbstring php-xml openssl policycoreutils
else
    printf 'Este instalador requiere Debian/Ubuntu (apt) o Fedora (dnf).\n' >&2
    exit 1
fi

SERVICIOS=(mariadb "$HTTPD_SERVICE")
if [[ "$DISTRO" == 'fedora' ]]; then
    SERVICIOS+=(php-fpm)
fi
systemctl enable --now "${SERVICIOS[@]}"

read -r -p 'Nombre del administrador [Administrador]: ' ADMIN_NOMBRE
ADMIN_NOMBRE="${ADMIN_NOMBRE:-Administrador}"
read -r -p 'Apellidos [Nuvi]: ' ADMIN_APELLIDOS
ADMIN_APELLIDOS="${ADMIN_APELLIDOS:-Nuvi}"
read -r -p 'Usuario administrador [admin]: ' ADMIN_USUARIO
ADMIN_USUARIO="${ADMIN_USUARIO:-admin}"
read -r -s -p 'Contraseña (mínimo 12 caracteres): ' ADMIN_PASSWORD
printf '\n'
read -r -s -p 'Confirma la contraseña: ' ADMIN_PASSWORD_CONFIRM
printf '\n'
if [[ "$ADMIN_PASSWORD" != "$ADMIN_PASSWORD_CONFIRM" || ${#ADMIN_PASSWORD} -lt 12 ]]; then
    printf 'Las contraseñas no coinciden o tienen menos de 12 caracteres.\n' >&2
    exit 1
fi
PASSWORD_BYTES="$(LC_ALL=C printf '%s' "$ADMIN_PASSWORD" | wc -c)"
if [[ ! "$ADMIN_USUARIO" =~ ^[A-Za-z0-9._-]{3,30}$ || ${#ADMIN_NOMBRE} -gt 50 || ${#ADMIN_APELLIDOS} -gt 50 || "$PASSWORD_BYTES" -gt 72 ]]; then
    printf 'Nombre o usuario inválido, o la contraseña supera 72 bytes.\n' >&2
    exit 1
fi

if [[ "$EUID" -eq 0 ]]; then
    mariadb_root() { mariadb --protocol=socket "$@"; }
else
    mariadb_root() { sudo mariadb --protocol=socket "$@"; }
fi

if [[ "$(mariadb_root --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '${DB_NAME}'")" != '0' ]]; then
    printf 'La base %s ya existe; por seguridad no se modificó. Elige otro nombre o revisa esa instalación.\n' "$DB_NAME" >&2
    exit 1
fi

DB_SUFFIX="$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
DB_USER="nuvi_${DB_SUFFIX}"
DB_PASSWORD="$(openssl rand -hex 24)"
mariadb_root <<SQL
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
SQL

mkdir -p "$APP_DIR"
bash "$SCRIPT_DIR/tools/copiar_archivos_instalacion.sh" "$SCRIPT_DIR" "$APP_DIR"

export NUVI_SETUP_DB_HOST=localhost
export NUVI_SETUP_DB_USER="$DB_USER"
export NUVI_SETUP_DB_PASSWORD="$DB_PASSWORD"
export NUVI_SETUP_DB_NAME="$DB_NAME"
php "$APP_DIR/tools/crear_config_inicial.php"
php "$APP_DIR/tools/importar_esquema_inicial.php"

export NUVI_ADMIN_NOMBRE="$ADMIN_NOMBRE"
export NUVI_ADMIN_APELLIDOS="$ADMIN_APELLIDOS"
export NUVI_ADMIN_USUARIO="$ADMIN_USUARIO"
export NUVI_ADMIN_PASSWORD="$ADMIN_PASSWORD"
php "$APP_DIR/tools/crear_admin_inicial.php"
unset ADMIN_PASSWORD ADMIN_PASSWORD_CONFIRM NUVI_ADMIN_PASSWORD

php "$APP_DIR/tools/ejecutar_migraciones_instalacion.php"

chown -R "root:$WEB_GROUP" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0750 {} +
find "$APP_DIR" -type f -exec chmod 0640 {} +
chmod 0640 "$APP_DIR/config/local.php"
if [[ "$DISTRO" == 'fedora' ]]; then
    APACHE_CONFIG='/etc/httpd/conf.d/tienda-nuvi.conf'
else
    APACHE_CONFIG='/etc/apache2/conf-available/tienda-nuvi.conf'
fi
cat > "$APACHE_CONFIG" <<APACHE
<Directory "$APP_DIR">
    Options -Indexes
    Require all granted
</Directory>
<Directory "$APP_DIR/config">
    Require all denied
</Directory>
APACHE
if [[ "$DISTRO" == 'fedora' ]]; then
    cat >> "$APACHE_CONFIG" <<'APACHE'
<FilesMatch \.php$>
    SetHandler "proxy:unix:/run/php-fpm/www.sock|fcgi://localhost"
</FilesMatch>
APACHE
    restorecon -R "$APP_DIR" "$APACHE_CONFIG"
    setsebool -P httpd_can_network_connect_db on
else
    a2enconf tienda-nuvi >/dev/null
fi
if [[ "$HTTPD_SERVICE" == 'httpd' ]]; then
    httpd -t
else
    apache2ctl configtest
fi
systemctl reload "$HTTPD_SERVICE"

printf '\nInstalación terminada. Abre: http://localhost/tienda-nuvi/login.php\n'
printf 'Usuario administrador: %s\n' "$ADMIN_USUARIO"
printf 'La contraseña no se guarda en texto claro; consérvala en un gestor seguro.\n'
unset DB_PASSWORD