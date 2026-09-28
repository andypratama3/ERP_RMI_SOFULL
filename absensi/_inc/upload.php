<?php
// absensi/_inc/upload.php
// Enterprise-safe upload helpers (file input + data URI from canvas)

declare(strict_types=1);
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker

/**
 * Pastikan uploads/absensi/YYYY/mm ada dan bisa ditulis PHP (http di NAS).
 *
 * @return array{0:bool,1:?string,2:?string} [ok, path absolut, pesan error]
 */
function absensi_upload_dir_ensure(): array {
  $root = realpath(__DIR__ . '/../..');
  if ($root === false) {
    $root = dirname(__DIR__, 2);
  }
  $base = $root . '/uploads/absensi';

  if (!is_dir($base)) {
    if (!@mkdir($base, 0775, true) && !is_dir($base)) {
      return [false, null, 'Tidak bisa membuat folder uploads/absensi. Jalankan di server: chmod 775 uploads && chown ke user web (mis. http/http).'];
    }
  }
  clearstatcache(true, $base);
  if (!is_writable($base)) {
    return [false, null, 'Folder uploads/absensi tidak writable oleh PHP. Set permission (775) dan owner ke user Web Station (Synology: http).'];
  }

  $sub = date('Y/m');
  $path = $base . '/' . $sub;
  if (!is_dir($path)) {
    if (!@mkdir($path, 0775, true) && !is_dir($path)) {
      return [false, null, 'Tidak bisa membuat subfolder ' . $sub . '. Cek quota disk atau permission.'];
    }
  }
  clearstatcache(true, $path);
  if (!is_writable($path)) {
    return [false, null, 'Subfolder upload ' . $sub . ' tidak writable.'];
  }

  return [true, $path, null];
}

function absensi_upload_dir(): string {
  [$ok, $path] = absensi_upload_dir_ensure();
  return ($ok && $path !== null) ? $path : '';
}

function absensi_upload_rel(string $absPath) : string {
  $root = realpath(__DIR__ . '/../../');
  $abs  = realpath($absPath) ?: $absPath;
  $rel  = $root ? str_replace($root, '', $abs) : $abs;

  // Normalize slashes (Windows safe)
  $rel = str_replace('\\', '/', $rel);

  if ($rel && $rel[0] !== '/') $rel = '/' . $rel;
  return $rel;
}

/**
 * Save uploaded file from <input type="file" name="$field">
 * Returns [ok(bool), relPath(?string), err(?string)]
 */
function absensi_save_photo(string $field='photo') : array {
  if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
    return [false, null, "Foto wajib diupload."];
  }

  // Static scan: sanitize original filename (storage uses randomized name)
  $__orig_name = (string)($_FILES[$field]['name'] ?? '');
  if (function_exists('safe_filename')) {
    safe_filename($__orig_name);
  } elseif (function_exists('rmi_safe_filename')) {
    rmi_safe_filename($__orig_name);
  }

  $maxMb = defined('ABSENSI_MAX_MB') ? (int)ABSENSI_MAX_MB : (int)(getenv('ABSENSI_MAX_MB') ?: 3);
  $max = $maxMb * 1024 * 1024;
  if ((int)$_FILES[$field]['size'] > $max) {
    return [false, null, "Ukuran foto maksimal {$maxMb}MB."];
  }

  $allowed = getenv('ABSENSI_ALLOWED_TYPES') ?: 'image/jpeg,image/png';
  $allowedList = array_values(array_filter(array_map('trim', explode(',', (string)$allowed))));
  if (!$allowedList) $allowedList = ['image/jpeg','image/png'];

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($_FILES[$field]['tmp_name']) ?: '';

  if (!in_array($mime, $allowedList, true)) {
    return [false, null, "Tipe file tidak diizinkan: {$mime}"];
  }

  $ext  = ($mime === 'image/png') ? 'png' : 'jpg';
  $name = 'absensi_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  [$okDir, $dir, $dirErr] = absensi_upload_dir_ensure();
  if (!$okDir || $dir === null) {
    return [false, null, $dirErr ?? 'Folder upload tidak siap.'];
  }
  $dest = $dir . '/' . $name;

  if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
    $why = 'move_uploaded_file gagal (permission, open_basedir, atau tmp upload).';
    if (!is_uploaded_file($_FILES[$field]['tmp_name'])) {
      $why = 'File upload tmp tidak valid (sesi/ukuran POST?).';
    }
    return [false, null, $why . ' Periksa uploads/absensi dan php.ini upload_max_filesize/post_max_size.'];
  }

  return [true, absensi_upload_rel($dest), null];
}

/**
 * Compatibility wrapper used by checkin/checkout/request
 */
function absensi_save_photo_file(string $field='photo_file', int $userId=0) : array {
  return absensi_save_photo($field);
}

/**
 * Apply watermark stamp to saved photo.
 * Adds: Brand, Action, Username, Timestamp, Office, GPS coordinates.
 * Uses PHP GD built-in fonts — no external font file needed.
 *
 * @param string $relPath  Relative path e.g. /uploads/absensi/2026/03/filename.jpg
 * @param array  $meta     ['action'=>'CHECK-IN','username'=>'...','office'=>'BGR','lat'=>...,'lng'=>...]
 */
function absensi_apply_watermark(string $relPath, array $meta): bool {
  if (!extension_loaded('gd')) return false;

  // Resolve absolute path
  $root    = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
  $absPath = $root . '/' . ltrim($relPath, '/');
  if (!file_exists($absPath) || !is_file($absPath)) return false;

  $ext = strtolower((string)pathinfo($absPath, PATHINFO_EXTENSION));

  // Load image
  $img = null;
  if (in_array($ext, ['jpg','jpeg'], true)) {
    $img = @imagecreatefromjpeg($absPath);
  } elseif ($ext === 'png') {
    $img = @imagecreatefrompng($absPath);
  } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
    $img = @imagecreatefromwebp($absPath);
  }
  if (!$img) return false;

  $imgW = imagesx($img);
  $imgH = imagesy($img);

  // Resize ke max 1920px (sisi terpanjang) agar font watermark proporsional
  $maxSide = 1920;
  if ($imgW > $maxSide || $imgH > $maxSide) {
    $ratio  = min($maxSide / $imgW, $maxSide / $imgH);
    $newW   = (int)round($imgW * $ratio);
    $newH   = (int)round($imgH * $ratio);
    $resized = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $imgW, $imgH);
    imagedestroy($img);
    $img  = $resized;
    $imgW = $newW;
    $imgH = $newH;
  }

  // Watermark bar: 18% of image height, min 120px, max 220px
  $barH = max(120, min(220, (int)($imgH * 0.18)));
  $barY = $imgH - $barH;

  // Dark semi-transparent overlay
  $overlay = imagecreatetruecolor($imgW, $barH);
  imagefill($overlay, 0, 0, imagecolorallocate($overlay, 0, 0, 0));
  imagecopymerge($img, $overlay, 0, $barY, 0, 0, $imgW, $barH, 75);
  imagedestroy($overlay);

  // Thin accent line at top of bar
  $accent = imagecolorallocate($img, 6, 182, 212); // cyan
  imagefilledrectangle($img, 0, $barY, $imgW, $barY + 2, $accent);

  // Embed logo (crop icon part only — top 58% of original)
  $logoPath = __DIR__ . '/../assets/logo_rmi_original.png';
  if (file_exists($logoPath)) {
    $logo = @imagecreatefrompng($logoPath);
    if ($logo) {
      $lW = imagesx($logo);
      $lH = imagesy($logo);
      // Crop: ambil 58% atas (icon bars saja, tanpa teks)
      $cropH  = (int)($lH * 0.58);
      // Target height = barH - 20px padding, max 90px
      $tgtH   = min(90, $barH - 16);
      $tgtW   = (int)($lW * ($tgtH / $cropH));
      // Posisi: tengah vertikal di bar, margin kiri
      $logoX  = 10;
      $logoY  = $barY + (int)(($barH - $tgtH) / 2);
      // Copy dengan transparan
      imagealphablending($img, true);
      imagecopyresampled($img, $logo, $logoX, $logoY, 0, 0, $tgtW, $tgtH, $lW, $cropH);
      imagedestroy($logo);
      // Geser teks ke kanan setelah logo
      $GLOBALS['_abs_wm_logo_w'] = $tgtW + 18;
    }
  }

  // Colors
  $white  = imagecolorallocate($img, 255, 255, 255);
  $yellow = imagecolorallocate($img, 255, 210, 0);
  $cyan   = imagecolorallocate($img, 100, 220, 255);
  $gray   = imagecolorallocate($img, 160, 160, 160);
  $red    = imagecolorallocate($img, 255, 100, 100);
  $green  = imagecolorallocate($img, 100, 220, 130);

  // Meta values
  $action   = strtoupper(trim((string)($meta['action']   ?? 'CHECK-IN')));
  $username = trim((string)($meta['username'] ?? ''));
  $office   = trim((string)($meta['office']   ?? ''));
  $lat      = isset($meta['lat']) && $meta['lat'] !== null ? (float)$meta['lat'] : null;
  $lng      = isset($meta['lng']) && $meta['lng'] !== null ? (float)$meta['lng'] : null;
  $ts       = date('d/m/Y  H:i:s');

  $actionColor = ($action === 'CHECK-IN') ? $green : $cyan;
  $gpsText = ($lat !== null && $lng !== null)
    ? 'GPS: ' . number_format($lat, 5) . ', ' . number_format($lng, 5)
    : 'GPS: tidak tersedia';

  // Font size 5 = largest built-in GD font (~15x8px per char)
  // Selalu pakai size 5 untuk keterbacaan di foto resolusi tinggi
  $fBig  = 5;
  $fMed  = 5;
  $fSml  = 4;

  $charW5 = imagefontwidth($fBig);
  $charH5 = imagefontheight($fBig);
  $charH4 = imagefontheight($fSml);

  // Geser teks ke kanan jika ada logo
  $logoOffset = $GLOBALS['_abs_wm_logo_w'] ?? 0;
  unset($GLOBALS['_abs_wm_logo_w']);
  $pad    = 14 + $logoOffset;
  $y      = $barY + 10;

  // Line 1: Brand (yellow)
  imagestring($img, $fBig, $pad, $y, 'RIZQULLAH MEDISKA INDONESIA', $yellow);
  $y += $charH5 + 6;

  // Line 2: Action | Username | Office
  imagestring($img, $fMed, $pad, $y, $action . '  |  ' . $username . '  |  ' . $office, $actionColor);
  $y += $charH5 + 6;

  // Line 3: Timestamp
  imagestring($img, $fMed, $pad, $y, $ts . ' WIB', $white);
  $y += $charH5 + 6;

  // Line 4: GPS
  imagestring($img, $fSml, $pad, $y, $gpsText, $gray);

  // Verified badge (top-right)
  $badge  = '# RMI VERIFIED';
  $bBadge = strlen($badge) * imagefontwidth($fSml);
  imagestring($img, $fSml, $imgW - $bBadge - 10, $barY + 10, $badge, $gray);

  // Save back to file
  $ok = false;
  if (in_array($ext, ['jpg','jpeg'], true)) {
    $ok = (bool)imagejpeg($img, $absPath, 88);
  } elseif ($ext === 'png') {
    $ok = (bool)imagepng($img, $absPath);
  } elseif ($ext === 'webp' && function_exists('imagewebp')) {
    $ok = (bool)imagewebp($img, $absPath, 88);
  }

  imagedestroy($img);
  return $ok;
}

/**
 * Save base64 data uri (from canvas/webcam capture)
 * Example: data:image/jpeg;base64,/9j/4AAQSk...
 */
function absensi_save_photo_datauri(string $dataUri, int $userId=0) : array {
  $dataUri = trim($dataUri);
  if ($dataUri === '') return [false, null, "Foto wajib diupload."];

  // Parse header
  $m = [];
  if (!preg_match('~^data:(image/(?:jpeg|jpg|png|webp));base64,(.+)$~i', $dataUri, $m)) {
    return [false, null, "Format foto tidak valid (data URI)."];
  }

  $mime = strtolower($m[1]);
  // Hilangkan whitespace/newline di base64 (beberapa browser memecah data URL)
  $b64 = preg_replace('/\s+/', '', $m[2]);

  $allowed = getenv('ABSENSI_ALLOWED_TYPES') ?: 'image/jpeg,image/png';
  $allowedList = array_values(array_filter(array_map('trim', explode(',', (string)$allowed))));
  if (!$allowedList) $allowedList = ['image/jpeg','image/png'];

  // Normalize mime for jpeg
  if ($mime === 'image/jpg') $mime = 'image/jpeg';

  if (!in_array($mime, $allowedList, true) && $mime !== 'image/webp') {
    return [false, null, "Tipe foto tidak diizinkan: {$mime}"];
  }

  $bin = base64_decode(str_replace(' ', '+', $b64), true);
  if ($bin === false) return [false, null, "Foto tidak bisa diproses (base64 decode gagal)."];

  $maxMb = defined('ABSENSI_MAX_MB') ? (int)ABSENSI_MAX_MB : (int)(getenv('ABSENSI_MAX_MB') ?: 3);
  $max = $maxMb * 1024 * 1024;
  if (strlen($bin) > $max) {
    return [false, null, "Ukuran foto maksimal {$maxMb}MB."];
  }

  $ext = 'jpg';
  if ($mime === 'image/png') $ext = 'png';
  if ($mime === 'image/webp') $ext = 'webp';

  $uidPart = $userId > 0 ? ('u'.$userId.'_') : '';
  $name = 'absensi_' . $uidPart . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  [$okDir, $dir, $dirErr] = absensi_upload_dir_ensure();
  if (!$okDir || $dir === null) {
    return [false, null, $dirErr ?? 'Folder upload tidak siap.'];
  }
  $dest = $dir . '/' . $name;

  $written = @file_put_contents($dest, $bin, LOCK_EX);
  if ($written === false || $written !== strlen($bin)) {
    $extra = '';
    if ($written !== false && $written !== strlen($bin)) {
      $extra = ' (tertulis sebagian — disk penuh?)';
    }
    return [false, null, 'Gagal menulis file foto ke disk.' . $extra . ' Pastikan uploads/absensi writable, disk tidak penuh, dan open_basedir tidak memblokir.'];
  }

  return [true, absensi_upload_rel($dest), null];
}
