<?php
/**
 * docs/panduan_view.php
 * Proxy baca dokumen panduan di docs/panduan/ — hanya user login + DOCS.VIEW (page registry).
 *
 * - ?f=path/relatif.md|html|svg|pdf — file di bawah docs/panduan/
 * - ?p=/sales/sales_do.php — resolve ke docs/panduan/sales/sales_do.{md,html,htm,svg}
 *
 * Token ?t= untuk iframe bila cookie tidak terkirim (sama seperti docs_view).
 */
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/_token_helper.php';

$token = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
$hasValidToken = $token !== '' && function_exists('docs_view_token_validate') && docs_view_token_validate($token);
if (!$hasValidToken) {
  require_login();
}

$root = dirname(__DIR__);
$panduanDir = $root . '/docs/panduan';

$f = isset($_GET['f']) ? trim((string)$_GET['f']) : '';
$routeP = isset($_GET['p']) ? trim((string)$_GET['p']) : '';

if ($routeP !== '' && $f === '') {
  $routeP = '/' . ltrim(str_replace('\\', '/', $routeP), '/');
  if (!preg_match('#^/([A-Za-z0-9_/-]+)\.php$#', $routeP, $mm)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Parameter p harus path script ERP, contoh: /sales/sales_do.php';
    exit;
  }
  $stem = $mm[1];
  $dirStem = dirname($stem);
  $baseStem = basename($stem);
  $baseReal = realpath($panduanDir);
  $foundRel = null;
  if ($baseReal !== false) {
    $prefStem = ($dirStem === '.' ? '' : $dirStem . '/') . 'panduan_' . $baseStem;
    foreach (['md', 'html', 'htm', 'svg'] as $ext) {
      foreach ([$prefStem, $stem] as $tryStem) {
        $try = $panduanDir . '/' . $tryStem . '.' . $ext;
        $rp = realpath($try);
        if ($rp !== false && is_file($rp) && str_starts_with($rp, $baseReal)) {
          $foundRel = ltrim(str_replace('\\', '/', substr($rp, strlen($baseReal))), '/');
          break 2;
        }
      }
    }
  }
  if ($foundRel === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $bp = '';
    if (function_exists('auth_base_project')) {
      $bp = rtrim((string)auth_base_project(), '/');
    }
    $hc = ($bp !== '' ? $bp : '') . '/docs/help_center.php';
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Panduan belum ada</title></head><body style="font-family:system-ui;padding:1.5rem;max-width:640px">';
    echo '<h1 style="font-size:1.2rem">Panduan belum tersedia</h1>';
    $hintPref = ($dirStem === '.' ? '' : $dirStem . '/') . 'panduan_' . $baseStem . '.md';
    echo '<p>Tambahkan file di server (SYS), contoh:</p><pre style="background:#f1f5f9;padding:12px;overflow:auto">docs/panduan/'
      . htmlspecialchars($hintPref, ENT_QUOTES, 'UTF-8') . "</pre>\n";
    echo '<p>Route: <code>' . htmlspecialchars($routeP, ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p><a href="' . htmlspecialchars($hc, ENT_QUOTES, 'UTF-8') . '">← Help Center</a></p></body></html>';
    exit;
  }
  $f = $foundRel;
}

if ($f === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Gunakan ?f=rel/path.md atau ?p=/modul/halaman.php\n";
  exit;
}

$f = str_replace(['../', '..\\', "\0"], '', $f);
$f = ltrim($f, '/\\');
if ($f === '' || strpos($f, '..') !== false) {
  http_response_code(400);
  exit('Path tidak valid.');
}

$path = realpath($panduanDir . '/' . $f);
if ($path === false || !is_file($path)) {
  http_response_code(404);
  exit('File tidak ditemukan.');
}

$baseReal = realpath($panduanDir);
if ($baseReal === false || !str_starts_with($path, $baseReal)) {
  http_response_code(403);
  exit('Akses ditolak.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

if ($ext === 'md') {
  $raw = file_get_contents($path);
  if ($raw === false) {
    http_response_code(500);
    exit('Gagal membaca file.');
  }
  header('Content-Type: text/html; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  $title = basename($path);
  echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head><body style="max-width:880px;margin:1rem auto;font-family:system-ui;line-height:1.55;background:#0f172a;color:#e2e8f0">';
  echo '<h1 style="font-size:1.15rem;font-weight:600">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><hr style="border-color:rgba(255,255,255,.12)">';
  echo '<pre style="white-space:pre-wrap;word-break:break-word;font-size:14px;margin:0">' . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '</pre></body></html>';
  exit;
}

$mimes = [
  'html' => 'text/html; charset=utf-8',
  'htm'  => 'text/html; charset=utf-8',
  'pdf'  => 'application/pdf',
  'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'doc'  => 'application/msword',
  'png'  => 'image/png',
  'jpg'  => 'image/jpeg',
  'jpeg' => 'image/jpeg',
  'svg'  => 'image/svg+xml; charset=utf-8',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($path) . '"');

$content = file_get_contents($path);
if ($content === false) {
  http_response_code(500);
  exit('Gagal membaca file.');
}

if ($ext === 'html' || $ext === 'htm') {
  $tokenSuffix = ($token !== '') ? ('&t=' . rawurlencode($token)) : '';
  $dir = dirname($f);
  $dir = ($dir === '.' || $dir === '') ? '' : (rtrim($dir, '/\\') . '/');

  $content = preg_replace_callback(
    '/\bhref\s*=\s*(["\'])([^"\'>#]+)\1/i',
    static function ($m) use ($dir, $tokenSuffix) {
      $url = trim($m[2]);
      if ($url === '' || $url[0] === '#' || preg_match('#^(https?:|mailto:|javascript:|data:)#i', $url)) {
        return $m[0];
      }
      if (strpos($url, 'panduan_view.php') !== false) {
        return $m[0];
      }
      $parts = explode('/', str_replace('\\', '/', $dir . $url));
      $out = [];
      foreach ($parts as $p) {
        if ($p === '' || $p === '.') {
          continue;
        }
        if ($p === '..') {
          if (array_pop($out) === null) {
            return $m[0];
          }
          continue;
        }
        $out[] = $p;
      }
      $resolved = implode('/', $out);
      if ($resolved === '') {
        return $m[0];
      }
      $q = 'panduan_view.php?f=' . rawurlencode($resolved) . $tokenSuffix;

      return 'href=' . $m[1] . $q . $m[1];
    },
    $content
  );
}

header('Content-Length: ' . (string)strlen($content));
echo $content;
exit;
