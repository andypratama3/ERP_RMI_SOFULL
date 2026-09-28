#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
CHANGELOG="$ROOT_DIR/CHANGELOG_ANDROID.md"
OUT_DIR="$ROOT_DIR/output/internal_release"
VERSION="${1:-}"

mkdir -p "$OUT_DIR"

if [[ ! -f "$CHANGELOG" ]]; then
  echo "CHANGELOG_ANDROID.md tidak ditemukan" >&2
  exit 1
fi

if [[ -z "$VERSION" ]]; then
  VERSION="v$(date +%Y.%m.%d)"
fi

OUT_FILE="$OUT_DIR/release_notes_${VERSION}.md"

{
  echo "# Release Notes ${VERSION}"
  echo
  echo "Generated at: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo
  awk '
    BEGIN { capture=0; printed=0 }
    /^## / {
      if (capture==1) exit
      if (printed==0) {
        capture=1
        printed=1
      }
    }
    {
      if (capture==1) print $0
    }
  ' "$CHANGELOG"
} > "$OUT_FILE"

echo "PASS release notes generated: $OUT_FILE"
