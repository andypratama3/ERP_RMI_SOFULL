#!/usr/bin/env bash
# ============================================================================
#  tools/qa/spreadsheet_regression.sh
# ============================================================================
#  Gate regresi untuk phpoffice/phpspreadsheet.
#
#  Kenapa perlu: ERP ini menerima file spreadsheet dari user. Advisory
#  phpspreadsheet bukan teoria—file-nya bisa diunggah dan diproses. Jadi saat
#  library di-patch (mis. 5.5.0 -> 5.8.1 menutup CVE-2026-34084), kita wajib
#  memastikan jalur tulis/baca masih benar, bukan cuma "composer audit hijau".
#
#  Yang diuji:
#    1. composer audit harus bersih (gate keras).
#    2. Versi terpasang memenuhi constraint ^5.8.1.
#    3. Tulis XLSX + baca ulang: sheet title, header, nilai, sel kosong.
#    4. Guard ENABLE_EXCEL_EXPORT: jalur XLSX harus tertutup saat env bukan 1.
#    5. Jalur CSV default tetap berfungsi.
#
#  Tidak butuh database. Jalankan: bash tools/qa/spreadsheet_regression.sh
# ============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 1

FAILED=0
pass() { echo "  OK    $1"; }
fail() { echo "  GAGAL $1"; FAILED=1; }

echo "== 1. composer audit =="
if composer audit --no-interaction 2>&1 | grep -q 'No security vulnerability'; then
  pass "tidak ada advisory"
else
  fail "masih ada advisory composer"
  composer audit --no-interaction 2>&1 | sed 's/^/        /' | head -n 20
fi

echo "== 2. versi phpspreadsheet =="
LOCKED="$(php -r '$l=json_decode(file_get_contents("composer.lock"),true); foreach($l["packages"] as $p){ if($p["name"]==="phpoffice/phpspreadsheet"){ echo $p["version"]; } }' 2>/dev/null)"
if [ -z "$LOCKED" ]; then
  fail "phpspreadsheet tidak ada di composer.lock"
else
  echo "  info  terpasang: $LOCKED"
  if php -r 'exit(version_compare($argv[1],"5.8.1",">=")?0:1);' "$LOCKED"; then
    pass "$LOCKED memenuhi floor 5.8.1"
  else
    fail "$LOCKED di bawah floor 5.8.1 (rentan)"
  fi
fi

echo "== 3. tulis + baca ulang XLSX =="
TMPD="$(mktemp -d)"
trap 'rm -rf "$TMPD"' EXIT
php "$ROOT/tools/qa/_spreadsheet_probe.php" "$TMPD" 2>&1 | sed 's/^/  /'
PROBE_RC="${PIPESTATUS[0]}"
if [ "$PROBE_RC" -eq 0 ]; then
  pass "probe XLSX + CSV"
else
  fail "probe XLSX + CSV (rc=$PROBE_RC)"
fi

echo
if [ "$FAILED" -eq 0 ]; then
  echo "SPREADSHEET REGRESSION: LULUS"
else
  echo "SPREADSHEET REGRESSION: GAGAL"
fi
exit "$FAILED"
