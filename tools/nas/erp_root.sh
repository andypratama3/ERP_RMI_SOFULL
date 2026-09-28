#!/usr/bin/env bash
set -euo pipefail
export ERP_CANONICAL_ROOT="/volume4/web/ERP_RMI_SOFULL"
if [[ "$(uname -s)" != "Linux" ]]; then
  echo "STOP: Must run on NAS Linux, not $(uname -s)." >&2; exit 2
fi
if [[ ! -d "${ERP_CANONICAL_ROOT}" ]]; then
  echo "STOP: Canonical root not found: ${ERP_CANONICAL_ROOT}" >&2; exit 2
fi
ERP_CANONICAL_ROOT="$(realpath "${ERP_CANONICAL_ROOT}")"
export ERP_CANONICAL_ROOT
PWD_REAL="$(realpath "${PWD}" 2>/dev/null || echo "${PWD}")"
if [[ "${PWD_REAL}" != "${ERP_CANONICAL_ROOT}" && "${PWD_REAL}" != "${ERP_CANONICAL_ROOT}/"* ]]; then
  echo "STOP: ${PWD_REAL} is not inside ${ERP_CANONICAL_ROOT}" >&2; exit 2
fi
echo "[erp_root] OK: ${ERP_CANONICAL_ROOT}" >&2
