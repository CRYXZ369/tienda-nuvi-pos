#!/usr/bin/env bash
# Compatibilidad con el cron anterior. Ejecuta el respaldo verificable.
set -Eeuo pipefail
ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$ROOT_DIR/tools/ejecutar_respaldo_verificado.sh"
