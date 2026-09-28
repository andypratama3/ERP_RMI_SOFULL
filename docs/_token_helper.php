<?php
/**
 * docs/_token_helper.php
 * Helper untuk token singkat akses docs (panduan_view, docs_view, governance, ops, archive).
 * Token self-contained: bisa divalidasi tanpa session (untuk iframe yang tidak kirim cookie).
 */
declare(strict_types=1);

if (!function_exists('docs_view_token_secret')) {
  function docs_view_token_secret(): string {
    if (function_exists('rmi_env')) {
      $v = rmi_env('APP_KEY', null);
      if ($v !== null && $v !== '') return (string)$v;
    }
    return defined('APP_KEY') ? (string)APP_KEY : 'rmi-docs-view-fallback';
  }
}

if (!function_exists('docs_view_token_generate')) {
  /**
   * Generate token untuk user yang sudah login.
   * Token valid ~5 menit (1 slot = 300 detik).
   */
  function docs_view_token_generate($userId): string {
    $uid = (string)($userId ?? '0');
    $expiry = (string)(int)floor(time() / 300);
    $secret = docs_view_token_secret();
    $mac = hash_hmac('sha256', 'docs_view_' . $uid . '_' . $expiry, $secret);
    return base64_encode($uid) . '.' . $expiry . '.' . $mac;
  }
}

if (!function_exists('docs_view_token_validate')) {
  /**
   * Validasi token. Return true jika valid (tanpa perlu session).
   */
  function docs_view_token_validate(string $token): bool {
    if ($token === '') return false;
    $parts = explode('.', $token, 3);
    if (count($parts) !== 3) return false;
    $uidB64 = $parts[0];
    $expiry = (int)$parts[1];
    $mac = $parts[2];
    if (strlen($mac) !== 64 || !ctype_xdigit($mac)) return false;
    $uid = base64_decode($uidB64, true);
    if ($uid === false || $uid === '') return false;
    $now = (int)floor(time() / 300);
    if ($expiry < $now - 1 || $expiry > $now + 1) return false;
    $secret = docs_view_token_secret();
    $expected = hash_hmac('sha256', 'docs_view_' . $uid . '_' . $expiry, $secret);
    return hash_equals($expected, $mac);
  }
}
