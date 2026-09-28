#!/bin/bash
# Regenerate DGM_O2C_DO_Lifecycle.svg dari .dot
# Run: bash tools/diagrams/render_o2c_svg.sh
# Atau di NAS: cd /volume4/web/ERP_RMI_SOFULL && dot -Tsvg docs/officepack/diagrams/DGM_O2C_DO_Lifecycle.dot -o docs/officepack/diagrams/DGM_O2C_DO_Lifecycle.svg

set -e
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DOT="$ROOT/docs/officepack/diagrams/DGM_O2C_DO_Lifecycle.dot"
SVG="$ROOT/docs/officepack/diagrams/DGM_O2C_DO_Lifecycle.svg"

if ! command -v dot &>/dev/null; then
  echo "ERROR: graphviz (dot) tidak terpasang. Install: brew install graphviz"
  echo "Atau jalankan di NAS: ssh user@nas 'cd /volume4/web/ERP_RMI_SOFULL && dot -Tsvg docs/officepack/diagrams/DGM_O2C_DO_Lifecycle.dot -o docs/officepack/diagrams/DGM_O2C_DO_Lifecycle.svg'"
  exit 1
fi

dot -Tsvg "$DOT" -o "$SVG"
echo "OK: SVG dihasilkan: $SVG"
