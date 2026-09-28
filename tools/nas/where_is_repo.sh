#!/usr/bin/env bash
# tools/nas/where_is_repo.sh — Audit lokasi repo ERP_RMI_SOFULL
# Usage: bash tools/nas/where_is_repo.sh
set -euo pipefail
CANONICAL="/volume4/web/ERP_RMI_SOFULL"
OUT_JSON="/volume4/web/ERP_RMI_SOFULL/storage/logs/repo_location_audit_last.json"

echo "=== ERP_RMI_SOFULL Repo Location Audit ==="
REAL=$(realpath "$CANONICAL" 2>/dev/null || echo "NOT_FOUND")
echo "Canonical : $CANONICAL"
echo "Realpath  : $REAL"

# Check symlink
if [ -L "$CANONICAL" ]; then
    echo "Is symlink: YES → $(readlink "$CANONICAL")"
else
    echo "Is symlink: NO (direct path)"
fi

# Check duplicates on other volumes
echo ""
echo "Checking for duplicates..."
DUPLICATES=()
for vol in /volume1 /volume2 /volume3 /volume4 /volume5 /Volumes; do
    candidate="$vol/web/ERP_RMI_SOFULL"
    [ "$candidate" = "$CANONICAL" ] && continue
    if [ -d "$candidate" ]; then
        echo "  ⚠️  DUPLICATE FOUND: $candidate"
        DUPLICATES+=("$candidate")
    fi
done

if [ ${#DUPLICATES[@]} -eq 0 ]; then
    echo "  ✅ No duplicates found"
fi

# Write JSON
DUPS_JSON="[]"
if [ ${#DUPLICATES[@]} -gt 0 ]; then
    DUPS_JSON=$(printf '"%s",' "${DUPLICATES[@]}" | sed 's/,$//' | sed 's/^/[/' | sed 's/$/]/')
fi

mkdir -p "$(dirname "$OUT_JSON")"
cat > "$OUT_JSON" << JSON
{
    "state_version": "repo_location_audit_v1",
    "generated_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "canonical_root": "$CANONICAL",
    "canonical_realpath": "$REAL",
    "is_symlink": $([ -L "$CANONICAL" ] && echo "true" || echo "false"),
    "duplicates_found": $([ ${#DUPLICATES[@]} -gt 0 ] && echo "true" || echo "false"),
    "duplicates": $DUPS_JSON,
    "overall_ok": $([ "$REAL" = "$CANONICAL" ] && [ ${#DUPLICATES[@]} -eq 0 ] && echo "true" || echo "false")
}
JSON

echo ""
echo "SINGLE SOURCE OF TRUTH = $CANONICAL"
echo "Evidence: $OUT_JSON"
