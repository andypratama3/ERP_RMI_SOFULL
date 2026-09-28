#!/bin/sh
# ensure_app_root.sh — Hard lock: wajib di /volume4/web/ERP_RMI_SOFULL (NAS).
# Usage: source "$(dirname "$0")/ensure_app_root.sh"
set -euo pipefail

ERP_ROOT="/volume4/web/ERP_RMI_SOFULL"
cd "$ERP_ROOT" || { echo "WRONG_ROOT"; exit 97; }
if [ "$(pwd)" != "$ERP_ROOT" ]; then echo "WRONG_ROOT"; exit 97; fi
if [ ! -f ".APP_ROOT_SENTINEL" ]; then echo "MISSING_SENTINEL"; exit 98; fi

export APP_ROOT="$ERP_ROOT"
export TOOLS_BASE_URL_INTERNAL="http://10.10.60.20/ERP_RMI_SOFULL"
export TOOLS_BASE_URL="${TOOLS_BASE_URL:-$TOOLS_BASE_URL_INTERNAL}"
