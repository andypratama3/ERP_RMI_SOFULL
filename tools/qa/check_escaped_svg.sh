#!/usr/bin/env bash
# =============================================================================
#  tools/qa/check_escaped_svg.sh  —  deteksi SVG yang TER-ESCAPE jadi teks
# =============================================================================
#  Gejalanya di browser:
#      <svg class="rmi-i rmi-i-home" ...>...</svg> Center
#  Artinya rmi_icon() (HTML) ikut masuk htmlspecialchars(). Penyebab paling
#  umum: ikon dirangkai ke dalam field `label`/string, lalu string itu
#  di-escape. Tidak bisa dicek static saja karena escaping bisa terjadi
#  3 lapis dari pemanggil, jadi halamannya benar-benar di-render.
#
#  Exit 0 = bersih, Exit 1 = ada SVG ter-escape.
#  Portable: bash 3.2 (macOS) sampai bash 5 (Ubuntu CI).
# =============================================================================
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1
ROOT="$PWD"

JOBS="${JOBS:-8}"
FILTER="${1:-}"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/rmi_svg.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

# Hanya halaman UI. Endpoint API/partials/helpers tidak dirender.
find . -name '*.php' \
  -not -path './vendor/*' -not -path './node_modules/*' -not -path './.git/*' \
  -not -path './storage/*' -not -path './uploads/*' -not -path './_backup/*' \
  -not -path './tools/*' -not -path './tests/*' -not -path './sql/*' \
  -not -path './docs/*' -not -path './config/*' -not -path './api/*' \
  2>/dev/null \
  | grep -vE '/(_inc|inc|partials?|includes?|helpers?|lib|_lib)/' \
  | grep -vE '/(bootstrap|config|db|auth|rbac)\.php$' \
  | grep -vE '__|_debug' \
  | sed 's|^\./||' | sort > "$TMP/all.txt"

if [ -n "$FILTER" ]; then
  grep -E "$FILTER" "$TMP/all.txt" > "$TMP/list.txt" || true
else
  cp "$TMP/all.txt" "$TMP/list.txt"
fi

COUNT=$(wc -l < "$TMP/list.txt" | tr -d ' ')

# Harness: sesi SYS, render halaman, laporkan hanya ada/tidaknya SVG ter-escape.
cat > "$TMP/h.php" << 'H'
<?php
error_reporting(E_ALL);
$rel = $argv[1];
$sd = sys_get_temp_dir() . '/rmi_svg_sess';
@mkdir($sd, 0775, true);
ini_set('session.save_path', $sd);
session_name('SG' . getmypid());
@session_start();
$_SESSION['user'] = ['username'=>'superadmin','name'=>'Super Admin','full_name'=>'Super Admin',
  'role'=>'SYS','level'=>'SYS','employee_code'=>'ADM001','department'=>'SYS',
  'office'=>'TGR','office_code'=>'TGR'];
$_SESSION['role']='SYS'; $_SESSION['dept']='SYS'; $_SESSION['level']='SYS';
$_SESSION['office']='TGR'; $_SESSION['logged_in']=true; $_SESSION['login_time']=time();
$_SERVER['SCRIPT_NAME']='/'.$rel; $_SERVER['REQUEST_URI']='/'.$rel; $_SERVER['PHP_SELF']='/'.$rel;
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_METHOD']='GET';
ob_start();
try { include $argv[2]; } catch (Throwable $e) {}
$html = (string) ob_get_clean();
// Buang nilai atribut data-icon-* :-escaping di dalam atribut WAJIB dan
// benar (browser meng-unescape saat parse, lalu JS menyuntik innerHTML).
// Kalau ikut dihitung, setiap halaman berlayout akan false-positive.
$html = preg_replace('/\sdata-icon-[a-z0-9_-]+\s*=\s*"[^"]*"/i', '', $html);
$hits = [];
foreach ([
  '&lt;svg'   => 'lt-svg',
  '&#60;svg'  => 'num60-svg',
  '&#x3C;svg' => 'hex3c-svg',
  '&lt;path d=' => 'lt-path',
  '&lt;circle'  => 'lt-circle',
  '&lt;rect'    => 'lt-rect',
  '&lt;line'    => 'lt-line',
  '&lt;polygon' => 'lt-polygon',
] as $needle => $tag) {
  $n = substr_count($html, $needle);
  if ($n > 0) $hits[] = "$tag=$n";
}
@file_put_contents($argv[3], json_encode(['hits' => $hits, 'len' => strlen($html)],
  JSON_UNESCAPED_UNICODE));
H

export ROOT TMP

echo "Memindai $COUNT halaman untuk SVG ter-escape (jobs=$JOBS)..."

# Render paralel. Satu baris hasil per halaman: "<file>\t<hits-json>".
render_one() {
  rel="$1"
  out="$TMP/res.$PPID.$RANDOM"
  rm -f "$out"
  APP_ROOT="$ROOT" php "$TMP/h.php" "$rel" "$ROOT/$rel" "$out" >/dev/null 2>&1
  if [ -s "$out" ]; then
    printf '%s\t%s\n' "$rel" "$(cat "$out")"
  else
    printf '%s\t%s\n' "$rel" '{"hits":["render-gagal"],"len":0}'
  fi
  rm -f "$out"
}
export -f render_one

xargs -P "$JOBS" -I{} sh -c 'render_one "$1"' _ {} < "$TMP/list.txt" > "$TMP/raw.txt" 2>/dev/null

# Pisahkan yang benar-benar punya temuan.
grep -v '"hits":\[\]' "$TMP/raw.txt" > "$TMP/hits.txt" || true
TOTAL=$(wc -l < "$TMP/hits.txt" | tr -d ' ')

if [ "$TOTAL" -eq 0 ]; then
  echo "BERSIH: tidak ada SVG ter-escape di $COUNT halaman."
  exit 0
fi

echo ""
echo "=== $TOTAL HALAMAN DENGAN SVG TER-ESCAPE ==="
sort "$TMP/hits.txt" | while IFS="$(printf '\t')" read -r rel js; do
  h="$(printf '%s' "$js" | sed 's/.*"hits":\[//; s/\].*//')"
  printf '  %-50s %s\n' "$rel" "$h"
done
echo ""
echo "Perbaikan: jangan rangkai rmi_icon() ke string yang di-escape."
echo "Simpan ikon dan label terpisah; cetak ikon sebagai HTML mentah."
exit 1
