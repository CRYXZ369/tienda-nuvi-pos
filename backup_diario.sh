#!/usr/bin/env bash
# Entrada recomendada para cron diario.
set -Eeuo pipefail
ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$ROOT_DIR/tools/ejecutar_respaldo_verificado.sh"
