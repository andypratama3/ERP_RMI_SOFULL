#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ANDROID_ROOT="$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)"

exec bash "$ANDROID_ROOT/e2e_watch/scripts/run_once_watch.sh"
