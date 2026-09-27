#!/usr/bin/env bash
# HTTPS privado y gratuito para Nuvi en una red local.
# Uso: sudo /bin/bash /var/www/html/Tienda/tools/instalar_https_local_nuvi.sh 127.0.0.1
set -Eeuo pipefail

if (( EUID != 0 )); then
    echo 'Ejecuta este instalador con sudo en el servidor de Nuvi.' >&2
    exit 2
fi

IP_SERVIDOR="${1:-127.0.0.1}"
if [[ ! "$IP_SERVIDOR" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    echo 'Indica una IPv4 válida, por ejemplo 127.0.0.1.' >&2
    exit 2
fi
IFS='.' read -r octeto1 octeto2 octeto3 octeto4 <<< "$IP_SERVIDOR"
for octeto in "$octeto1" "$octeto2" "$octeto3" "$octeto4"; do
    if (( 10#$octeto > 255 )); then
        echo 'La IPv4 indicada contiene un octeto fuera de rango.' >&2
        exit 2
    fi
done

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
WEB_DIR='/var/www/html'
APP_DIR="$WEB_DIR/Tienda"
CERT_DIR='/etc/ssl/nuvi-local-ca'
CA_KEY="$CERT_DIR/nuvi-local-ca.key"
CA_CRT="$CERT_DIR/nuvi-local-ca.crt"
SERVER_KEY="$CERT_DIR/nuvi-server.key"
SERVER_CSR="$CERT_DIR/nuvi-server.csr"
SERVER_CRT="$CERT_DIR/nuvi-server.crt"
SITE_NAME='nuvi-https-local.conf'
SITE_FILE="/etc/apache2/sites-available/$SITE_NAME"
MARCA="$(date +%Y%m%d_%H%M%S)"
RESPALDO_SITE="$SITE_FILE.before-nuvi-tls-$MARCA"
TEMP_SITE=''
TEMP_EXT=''
TENIA_SITE=0

limpiar() {
    [[ -n "$TEMP_SITE" ]] && rm -f -- "$TEMP_SITE"
    [[ -n "$TEMP_EXT" ]] && rm -f -- "$TEMP_EXT"
}
trap limpiar EXIT

for comando in apache2ctl a2enmod a2ensite a2dissite curl openssl systemctl install; do
    command -v "$comando" >/dev/null 2>&1 || {
        echo "Falta el comando requerido: $comando" >&2
        exit 3
    }
done
if [[ ! -d "$APP_DIR" || ! -f "$APP_DIR/punto_de_venta.php" ]]; then
    echo "No se encontró Nuvi en $APP_DIR. No se modificó Apache." >&2
    exit 3
fi
if [[ "$ROOT_DIR" != "$APP_DIR" ]]; then
    echo "Este instalador debe ejecutarse desde la instalación web de Nuvi ($APP_DIR)." >&2
    exit 3
fi

# La clave permanece 600 para root. El directorio sólo permite atravesar para
# que Apache pueda leer el certificado público, sin poder listar su contenido.
install -d -m 711 -o root -g root -- "$CERT_DIR"
chmod 711 -- "$CERT_DIR"

if [[ -e "$CA_KEY" || -e "$CA_CRT" ]]; then
    if [[ ! -f "$CA_KEY" || ! -f "$CA_CRT" ]]; then
        echo 'La autoridad local de Nuvi está incompleta; no se sustituirá automáticamente.' >&2
        exit 4
    fi
else
    echo 'Creando la autoridad certificadora privada de Nuvi...'
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out "$CA_KEY"
    openssl req -x509 -new -sha256 -days 3650 -key "$CA_KEY" -out "$CA_CRT" \
        -subj '/C=MX/O=Miscelanea Nuvi/CN=Nuvi LAN Local CA' \
        -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
        -addext 'keyUsage=critical,keyCertSign,cRLSign' \
        -addext 'subjectKeyIdentifier=hash'
fi
chmod 600 -- "$CA_KEY"
chmod 644 -- "$CA_CRT"

if [[ ! -f "$SERVER_KEY" || ! -f "$SERVER_CRT" ]] || ! openssl x509 -checkend $((30 * 24 * 3600)) -noout -in "$SERVER_CRT" >/dev/null 2>&1; then
    echo "Creando certificado HTTPS para $IP_SERVIDOR ..."
    rm -f -- "$SERVER_KEY" "$SERVER_CSR" "$SERVER_CRT"
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out "$SERVER_KEY"
    openssl req -new -key "$SERVER_KEY" -out "$SERVER_CSR" -subj "/CN=$IP_SERVIDOR"
    TEMP_EXT="$(mktemp /tmp/nuvi-https-ext.XXXXXX)"
    printf '%s\n' \
        'basicConstraints=critical,CA:FALSE' \
        'keyUsage=critical,digitalSignature,keyEncipherment' \
        'extendedKeyUsage=serverAuth' \
        "subjectAltName=IP:$IP_SERVIDOR" \
        'subjectKeyIdentifier=hash' \
        'authorityKeyIdentifier=keyid,issuer' > "$TEMP_EXT"
    openssl x509 -req -sha256 -days 825 -in "$SERVER_CSR" -CA "$CA_CRT" -CAkey "$CA_KEY" \
        -CAcreateserial -out "$SERVER_CRT" -extfile "$TEMP_EXT"
fi
chmod 600 -- "$SERVER_KEY"
chmod 644 -- "$SERVER_CRT"

a2enmod ssl headers >/dev/null

if [[ -f "$SITE_FILE" ]]; then
    TENIA_SITE=1
    cp --preserve=mode,timestamps -- "$SITE_FILE" "$RESPALDO_SITE"
fi

TEMP_SITE="$(mktemp /etc/apache2/sites-available/.nuvi-https.XXXXXX)"
cat > "$TEMP_SITE" <<EOF
<IfModule mod_ssl.c>
    <VirtualHost *:443>
        ServerName $IP_SERVIDOR
        DocumentRoot $WEB_DIR

        SSLEngine on
        SSLCertificateFile $SERVER_CRT
        SSLCertificateKeyFile $SERVER_KEY
        SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1

        <Directory $WEB_DIR>
            Options FollowSymLinks
            AllowOverride All
            Require all granted
        </Directory>

        Header always set X-Content-Type-Options "nosniff"
        Header always set Referrer-Policy "same-origin"
        ErrorLog \${APACHE_LOG_DIR}/nuvi-https-error.log
        CustomLog \${APACHE_LOG_DIR}/nuvi-https-access.log combined
    </VirtualHost>
</IfModule>
EOF
install -m 644 -- "$TEMP_SITE" "$SITE_FILE"
a2ensite "$SITE_NAME" >/dev/null

if ! apache2ctl configtest; then
    echo 'La configuración de Apache no pasó la validación; se restaurará el sitio previo.' >&2
    a2dissite -q "$SITE_NAME" || true
    if (( TENIA_SITE == 1 )); then
        cp --preserve=mode,timestamps -- "$RESPALDO_SITE" "$SITE_FILE"
        a2ensite "$SITE_NAME" >/dev/null || true
    else
        rm -f -- "$SITE_FILE"
    fi
    apache2ctl configtest || true
    exit 5
fi

systemctl reload apache2

# Un reinicio graceful puede tardar unos instantes en abrir el socket TLS. Se
# prueba la IP real del servidor, no sólo loopback, porque ésa es la ruta que
# usarán las tablets.
respuesta=''
verificado=0
for intento in 1 2 3 4 5; do
    if respuesta="$(curl --fail --silent --show-error --noproxy '*' --connect-timeout 5 \
        --cacert "$CA_CRT" "https://$IP_SERVIDOR/Tienda/health_check.php" 2>/dev/null)" \
        && [[ "$respuesta" == 'OK' ]]; then
        verificado=1
        break
    fi
    sleep 1
done

if (( verificado == 0 )); then
    echo 'Apache aceptó la configuración HTTPS, pero Nuvi no pasó la verificación local.' >&2
    echo 'Revisa /var/log/apache2/nuvi-https-error.log y health_check.php antes de usar tablets.' >&2
    exit 6
fi

echo
echo 'HTTPS local de Nuvi quedó activo.'
echo "Abre: https://$IP_SERVIDOR/Tienda/"
echo "Certificado público para instalar en las tablets: $CA_CRT"
echo 'Antes de abrir esa dirección en una tablet, instala ese certificado como “Certificado de CA”.'
echo 'No se redirigió HTTP automáticamente: conserva la dirección anterior mientras preparas cada dispositivo.'
