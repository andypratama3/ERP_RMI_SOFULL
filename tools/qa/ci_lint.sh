#!/usr/bin/env bash
# =============================================================================
#  tools/qa/ci_lint.sh  --  gate cepat, TANPA database
# =============================================================================
#  Dipakai oleh GitHub Actions (.github/workflows/ci.yml) DAN bisa dijalankan
#  lokal sebelum push. Hanya pemeriksaan yang tidak butuh MySQL, karena
#  runner GitHub tidak punya akses ke database ERP internal/NAS.
#
#  Exit 0 = semua lolos. Exit 1 = ada yang gagal (CI jadi merah).
# =============================================================================
set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1
ROOT="$PWD"
FAILED=0
SKIPPED=0

hdr() { printf '\n\033[1m=== %s ===\033[0m\n' "$1"; }
ok()  { printf '  \033[32mOK\033[0m    %s\n' "$1"; }
bad() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAILED=$((FAILED + 1)); }
skip(){ printf '  \033[33mSKIP\033[0m  %s\n' "$1"; SKIPPED=$((SKIPPED + 1)); }

# -----------------------------------------------------------------------------
hdr "1. PHP syntax -- seluruh file"
# Pakai -P 8 -n 1: phpcs per berkas, output bisa Says "Errors parsing" tapi
# xargs tetap lanjut (kita grep setelahnya). -n 1 memastikan file yang fatal
# tidak memblokir pemeriksaan file lain.
PHP_FILES=$(find . -name '*.php' \
  -not -path './vendor/*' -not -path './node_modules/*' \
  -not -path './.git/*' | wc -l | tr -d ' ')
echo "  memindai $PHP_FILES file..."
LINT_OUT=$(find . -name '*.php' \
  -not -path './vendor/*' -not -path './node_modules/*' \
  -not -path './.git/*' -print0 \
  | xargs -0 -P 8 -n 1 php -l 2>&1 | grep -v '^No syntax errors detected' || true)
if [ -z "$LINT_OUT" ]; then
  ok "semua $PHP_FILES file PHP bebas syntax error"
else
  bad "ada file PHP dengan syntax error:"
  echo "$LINT_OUT" | head -n 40 | sed 's/^/        /'
fi

# -----------------------------------------------------------------------------
hdr "2. JavaScript syntax"
if command -v node >/dev/null 2>&1; then
  JS_FILES=$(find . -name '*.js' -not -path './vendor/*' -not -path './node_modules/*' | wc -l | tr -d ' ')
  JS_BAD=0
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    if ! node --check "$f" >/dev/null 2>&1; then
      echo "        $f"; JS_BAD=$((JS_BAD + 1))
    fi
  done < <(find . -name '*.js' -not -path './vendor/*' -not -path './node_modules/*')
  if [ "$JS_BAD" -eq 0 ]; then ok "semua $JS_FILES file JS lolos node --check"
  else bad "$JS_BAD file JS tidak lolos"; fi
else
  skip "node tidak tersedia -- cek JS dilewati"
fi

# -----------------------------------------------------------------------------
hdr "3. JSON valid"
JSON_BAD=0
while IFS= read -r f; do
  [ -n "$f" ] || continue
  python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$f" >/dev/null 2>&1 || { echo "        $f"; JSON_BAD=$((JSON_BAD + 1)); }
done < <(find . -name '*.json' -not -path './vendor/*' -not -path './node_modules/*' -not -path './.git/*' -not -path './storage/*' -not -path './uploads/*')
if [ "$JSON_BAD" -eq 0 ]; then ok "semua berkas JSON valid"; else bad "$JSON_BAD JSON tidak valid"; fi

# -----------------------------------------------------------------------------
hdr "4. composer.json"
if command -v composer >/dev/null 2>&1; then
  if composer validate --no-check-publish --no-check-lock >/dev/null 2>&1; then
    ok "composer.json valid"
  else
    bad "composer.json tidak valid:"; composer validate --no-check-publish 2>&1 | head -n 15 | sed 's/^/        /'
  fi
else
  skip "composer tidak tersedia"
fi

# -----------------------------------------------------------------------------
hdr "5. Guard repo (tanpa DB)"
# Hanya tool yang exit-0 di lingkungan tanpa database.
for t in php_error_scan migration_sql_lint no_cyrillic_guard path_guard \
         path_police unicode_guard module_governance_lint volumes_guard runbook_check; do
  f="tools/qa/$t.php"
  if [ ! -f "$f" ]; then skip "$t (tidak ada)"; continue; fi
  out=$(php "$f" 2>&1); code=$?
  if [ "$code" -eq 0 ]; then ok "$t"
  else
    bad "$t (exit $code)"
    echo "$out" | head -n 12 | sed 's/^/        /'
  fi
done

# -----------------------------------------------------------------------------
hdr "6. Hygiene git"
if git diff --check >/dev/null 2>&1; then ok "tidak ada whitespace error"; else bad "ada whitespace error (git diff --check)"; fi

# ---------------------------------------------------------------------------
# base_path_guard & volumes_police sengaja DILEWATI: keduanya membandingkan
# APP_ROOT lokal dengan path NAS (/volume4/web/ERP_RMI_SOFULL). Runner GitHub
# dijamin tidak sama dengan NAS, jadi menjalankan keduanya hanya menghasilkan
# merah palsu. Keduanya dijalankan pada job `qa-nas` (self-hosted runner).
for t in base_path_guard volumes_police; do skip "$t (butuh APP_ROOT NAS -- lihat job qa-nas)"; done

# dependency_audit butuh vendor/ terpasang; secret_scan masih heuristik
# (false-positive pada nama kolom SQL "token" dan docblock Authorization).
# dependency_audit sengaja ADVISORY di sini, bukan gate. Temuan nyata saat ini:
#   phpoffice/phpspreadsheet sudah di-patch ke 5.8.1 (audit bersih).
#   Kalau muncul advisory lagi, perbaiki dulu lalu catat di
#   ERP_MASTER_TASK_TRACKER.md. Gate keras diletakkan di job `security`.
if [ -f composer.lock ] && command -v composer >/dev/null 2>&1; then
  # --locked WAJIB. Tanpa flag itu composer audit membaca paket yang terpasang
  # di vendor/. Di GitHub-hosted runner vendor/ tidak ada, jadi audit keluar
  # "No installed packages found" dan tidak memeriksa apa pun.
  audit_json=$(composer audit --locked --format=json 2>/dev/null)
  if [ -z "$audit_json" ]; then
    printf '  \033[33mWARN\033[0m  composer audit: tidak bisa dibaca, advisory TIDAK diverifikasi\n'
    SKIPPED=$((SKIPPED + 1))
  else
    n=$(printf '%s' "$audit_json" | python3 -c "
import sys,json
d=json.load(sys.stdin); a=d.get('advisories',d)
# advisories bisa [] (bersih) atau dict per-paket (ada temuan)
if isinstance(a,dict): print(sum(len(v) for v in a.values()))
elif isinstance(a,list): print(len(a))
else: print(0)" 2>/dev/null || echo '?')
    if [ "$n" = "0" ]; then
      ok "composer audit --locked: tidak ada advisory"
    else
      printf '  \033[33mWARN\033[0m  composer audit: %s advisory belum patched (lihat tracker)\n' "$n"
      SKIPPED=$((SKIPPED + 1))
    fi
  fi
else
  skip "composer audit (composer/composer.lock tidak tersedia)"
fi
if [ -f tools/qa/secret_scan.php ]; then
  out=$(php tools/qa/secret_scan.php 2>&1)
  printf '  \033[33mINFO\033[0m  secret_scan (advisory, tidak memblokir) -> %s\n' \
    "$(echo "$out" | head -c 120)"
  SKIPPED=$((SKIPPED + 1))
fi

# -----------------------------------------------------------------------------
printf '\n'
if [ "$FAILED" -eq 0 ]; then
  printf '\033[32m=== CI LINT: LULUS ===\033[0m (ada %s check dilewati)\n' "$SKIPPED"
  exit 0
fi
printf '\033[31m=== CI LINT: %s CHECK GAGAL ===\033[0m\n' "$FAILED"
exit 1
