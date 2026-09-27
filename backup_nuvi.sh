#!/usr/bin/env bash
# Respaldo manual con envío a la laptop. Requiere llave SSH ya configurada.
set -Eeuo pipefail
ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
NUVI_UPLOAD_REMOTE=1 exec bash "$ROOT_DIR/tools/crear_respaldo.sh"
