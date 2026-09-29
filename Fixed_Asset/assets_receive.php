<?php
/*
PATCH untuk /volume4/web/ERP_RMI_SOFULL/Fixed_Asset/assets.php
Tujuan: tombol Terima ITC wajib upload foto bukti kecocokan barang.
Alur tidak berubah:
Mgr ITC -> PQP beli -> FIN approve -> PQP dibeli -> ITC terima + foto -> ACT register fixed asset.
*/

/* 1) Tambahkan helper upload, letakkan setelah helper umum / sebelum handler POST */
function fa_upload_itc_receive_photo(string $fieldName, string $requestCode): ?string {
    if (empty($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name'])) return null;
    if (!isset($_FILES[$fieldName]['error']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload foto penerimaan ITC gagal.');
    }

    $allowedExt = ['jpg','jpeg','png','webp'];
    $orig = (string)$_FILES[$fieldName]['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Format foto harus JPG, JPEG, PNG, atau WEBP.');
    }

    $maxBytes = 5 * 1024 * 1024;
    if ((int)$_FILES[$fieldName]['size'] > $maxBytes) {
        throw new RuntimeException('Ukuran foto maksimal 5MB.');
    }

    $baseDir = __DIR__ . '/uploads/asset_receive';
    if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);

    $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', $requestCode);
    $fileName = $safeCode . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $baseDir . '/' . $fileName;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) {
        throw new RuntimeException('Foto gagal disimpan ke folder upload.');
    }

    return 'uploads/asset_receive/' . $fileName;
}

/* 2) Tambahkan/ubah handler POST untuk tombol Terima ITC */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'itc_receive_asset') {
    // Pastikan permission tetap sama: hanya ITC yang boleh menerima.
    // Contoh: require_any_permission(['FIXED_ASSET.PURCHASE_ITC']);

    $id = (int)($_POST['request_id'] ?? 0);
    $note = trim((string)($_POST['itc_receive_note'] ?? ''));
    $matchStatus = (string)($_POST['itc_receive_match_status'] ?? 'MATCH');
    if (!in_array($matchStatus, ['MATCH','NOT_MATCH'], true)) $matchStatus = 'MATCH';

    $stmt = $pdo->prepare("SELECT id, request_code, status FROM fa_purchase_requests WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) throw new RuntimeException('Request aset tidak ditemukan.');
    if ($req['status'] !== 'PURCHASED') {
        throw new RuntimeException('Terima ITC hanya boleh setelah status DIBELI PQP / PURCHASED.');
    }

    $photoPath = fa_upload_itc_receive_photo('itc_receive_photo', $req['request_code']);
    if (!$photoPath) {
        throw new RuntimeException('Foto bukti penerimaan ITC wajib diupload.');
    }

    if ($matchStatus === 'NOT_MATCH') {
        $newStatus = 'RECEIVED_MISMATCH_ITC'; // belum boleh ACT register
    } else {
        $newStatus = 'RECEIVED_BY_ITC';       // lanjut ke ACT register
    }

    $upd = $pdo->prepare("\n        UPDATE fa_purchase_requests\n        SET received_at = NOW(),\n            received_by = ?,\n            itc_receive_photo = ?,\n            itc_receive_photo_note = ?,\n            itc_receive_match_status = ?,\n            status = ?,\n            updated_at = NOW()\n        WHERE id = ?\n    ");
    $upd->execute([$currentUserName ?? ($_SESSION['username'] ?? 'ITC'), $photoPath, $note, $matchStatus, $newStatus, $id]);

    $audit = $pdo->prepare("\n        INSERT INTO fa_purchase_audit (request_id, status_from, status_to, actor_dept, actor_name, note, created_at)\n        VALUES (?, 'PURCHASED', ?, 'ITC', ?, ?, NOW())\n    ");
    $audit->execute([$id, $newStatus, $currentUserName ?? ($_SESSION['username'] ?? 'ITC'), $note]);

    header('Location: assets.php#asset-purchase-flow');
    exit;
}

/* 3) Ubah form tombol Terima ITC di tabel workflow.
   Ganti form lama yang hanya note + tombol Terima ITC menjadi contoh berikut. */
?>
<form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
  <input type="hidden" name="action" value="itc_receive_asset">
  <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">

  <select name="itc_receive_match_status" required>
    <option value="MATCH">Barang sesuai</option>
    <option value="NOT_MATCH">Barang tidak sesuai</option>
  </select>

  <input type="file" name="itc_receive_photo" accept="image/jpeg,image/png,image/webp" required>

  <input type="text" name="itc_receive_note" placeholder="Catatan penerimaan ITC / kondisi barang" value="">

  <button type="submit" class="btn btn-warning">Terima ITC</button>
</form>

<?php
/* 4) Saat ACT register asset, pastikan hanya status RECEIVED_BY_ITC yang boleh masuk fa_assets.
   Jangan izinkan RECEIVED_MISMATCH_ITC.
   Blok ini hanya valid bila file ini di-require dari assets.php (handler POST sudah
   mengisi $req). Diakses langsung sebagai halaman, $req tidak ada — jangan fatal. */
$__faItcRequest = isset($req) && is_array($req) ? $req : null;
if ($__faItcRequest !== null && ($__faItcRequest['status'] ?? '') !== 'RECEIVED_BY_ITC') {
    throw new RuntimeException('ACT hanya dapat register aset jika ITC sudah menerima barang dan status barang sesuai.');
}

/* 5) Tambahkan tampilan bukti foto pada tabel workflow, misalnya di kolom Status atau Aksi. */
if (isset($row['itc_receive_photo']) && !empty($row['itc_receive_photo'])): ?>
  <a href="<?= htmlspecialchars($row['itc_receive_photo']) ?>" target="_blank">Lihat Foto ITC</a>
<?php endif; ?>
