#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
OUT_FILE="${1:-$ROOT_DIR/android_app/output/internal_release/release_notes_auto.md}"

mkdir -p "$(dirname "$OUT_FILE")"

if ! command -v git >/dev/null 2>&1 || [ ! -d "$ROOT_DIR/.git" ]; then
  cat > "$OUT_FILE" <<'EOF'
# Release Notes (Auto)

## Ringkasan
- Repository bukan git working tree pada environment ini.
- Ringkasan perubahan otomatis dari diff tidak tersedia.

## Known Risks
- Pastikan validasi manual changelog sebelum distribusi.

## Rollback Notes
- Gunakan APK hotfix dengan `versionCode` lebih tinggi.
EOF
  echo "PASS generated fallback release notes: $OUT_FILE"
  exit 0
fi

LAST_TAG="$(git -C "$ROOT_DIR" describe --tags --abbrev=0 2>/dev/null || true)"
RANGE="${LAST_TAG:+$LAST_TAG...}HEAD"
CHANGED="$(git -C "$ROOT_DIR" diff --name-only "$RANGE")"

count_tasks="$(printf '%s\n' "$CHANGED" | awk '/android_app\/app\/src\/main\/java\/.*\/feature\/tasks\//{c++} END{print c+0}')"
count_dashboard="$(printf '%s\n' "$CHANGED" | awk '/android_app\/app\/src\/main\/java\/.*\/feature\/dashboard\//{c++} END{print c+0}')"
count_authnet="$(printf '%s\n' "$CHANGED" | awk '/android_app\/app\/src\/main\/java\/.*\/core\/network\//{c++} END{print c+0}')"
count_sync="$(printf '%s\n' "$CHANGED" | awk '/android_app\/app\/src\/main\/java\/.*\/core\/sync\/|android_app\/app\/src\/main\/java\/.*\/data\/local\/|android_app\/app\/src\/main\/java\/.*\/data\/repository_impl\//{c++} END{print c+0}')"

{
  echo "# Release Notes (Auto)"
  echo
  echo "## Ringkasan"
  [ "$count_tasks" -gt 0 ] && echo "- Tasks: $count_tasks file berubah"
  [ "$count_dashboard" -gt 0 ] && echo "- Dashboard: $count_dashboard file berubah"
  [ "$count_authnet" -gt 0 ] && echo "- Auth & Networking: $count_authnet file berubah"
  [ "$count_sync" -gt 0 ] && echo "- Sync & Offline: $count_sync file berubah"
  if [ "$count_tasks" -eq 0 ] && [ "$count_dashboard" -eq 0 ] && [ "$count_authnet" -eq 0 ] && [ "$count_sync" -eq 0 ]; then
    echo "- Tidak ada perubahan modul utama terdeteksi dari diff."
  fi
  echo
  echo "## Known Risks"
  echo "- Pastikan `connectedDebugAndroidTest` dijalankan pada emulator bersih jika ada perubahan signing key."
  echo "- Jika `apksigner` belum tersedia, verifikasi signature dilakukan terbatas."
  echo
  echo "## Rollback Notes"
  echo "- Rollback via hotfix APK dengan `versionCode` lebih tinggi."
  echo "- Hindari downgrade APK karena Android package manager akan menolak."
} > "$OUT_FILE"

echo "PASS generated release notes: $OUT_FILE"
