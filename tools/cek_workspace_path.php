<?php
/**
 * cek_workspace_path.php — Verifikasi path workspace agar edit terlihat di web.
 *
 * Jalankan dari project root untuk cek apakah tim edit di lokasi yang benar.
 * Output: panduan jelas ke stdout.
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$cwd = getcwd();

echo "\n";
echo "=== CEK WORKSPACE PATH — ERP_RMI_SOFULL ===\n\n";

$isMacMount = (strpos($root, '/Volumes/') !== false);
$isNas = (strpos($root, '/volume4/') !== false);

echo "Path project saat ini: " . $root . "\n\n";

if ($isMacMount) {
    echo "STATUS: Mac (SMB mount)\n";
    echo "  → Path /Volumes/web/ = mount share dari NAS.\n";
    echo "  → Jika share 'web' dari RMI-2025 terhubung, edit LANGSUNG ke file NAS.\n";
    echo "  → Perubahan harus terlihat di https://erp.rizqullahmediska.com\n\n";
    echo "CEK:\n";
    echo "  1. Finder → Lokasi → RMI-2025 → web → ERP_RMI_SOFULL\n";
    echo "  2. Pastikan Cursor/VS Code membuka folder INI (bukan copy lokal)\n";
    echo "  3. File → Open → pilih folder di dalam share 'web'\n\n";
    echo "JIKA EDIT TIDAK MUNCUL:\n";
    echo "  - Mungkin buka folder lokal (mis. ~/Projects/...) → SALAH\n";
    echo "  - Harus buka folder dari share: /Volumes/web/ERP_RMI_SOFULL\n";
    echo "  - Atau deploy manual: scp/rsync ke NAS (lihat docs/LANGKAH_DEPLOY_KE_NAS.md)\n\n";
} elseif ($isNas) {
    echo "STATUS: NAS\n";
    echo "  → Edit langsung di server. Perubahan langsung terlihat.\n\n";
} else {
    echo "STATUS: Path lain (mungkin lokal)\n";
    echo "  → Jika ini copy lokal, perubahan TIDAK otomatis ke NAS.\n";
    echo "  → Deploy manual diperlukan: scp/rsync ke RMI-2025:/volume4/web/ERP_RMI_SOFULL/\n";
    echo "  → Lihat: docs/LANGKAH_DEPLOY_KE_NAS.md\n\n";
}

echo "Web server baca dari: /volume4/web/ERP_RMI_SOFULL (di NAS)\n";
echo "URL: https://erp.rizqullahmediska.com/ERP_RMI_SOFULL/\n\n";
