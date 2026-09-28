<?php
$title="Riwayat Absensi";
require_once __DIR__ . "/_inc/bootstrap.php";

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.VIEW');

require_once __DIR__ . "/_layout_top.php";


$uid = (int)$ABS_USER['id'];
$limit = 120;

$stmt = $pdo->prepare("SELECT id, action_type, office_code, distance_m, geo_lat, geo_lng, geo_acc, photo_path, created_at
                       FROM absensi_logs
                       WHERE user_id=?
                       ORDER BY id DESC
                       LIMIT {$limit}");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="grid">
  <div class="card col-12">
    <div class="h1">Riwayat Terakhir (<?= (int)$limit ?>)</div>
    <table class="table" style="margin-top:10px">
      <thead>
        <tr>
          <th>Waktu</th><th>Aksi</th><th>Kantor</th><th>Jarak</th><th>Akurasi</th><th>Foto</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['created_at'],ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['action_type'],ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['office_code'] ?: '-',ENT_QUOTES,'UTF-8') ?></td>
          <td><?= $r['distance_m']===null?'-':((int)$r['distance_m']).' m' ?></td>
          <td><?= $r['geo_acc']===null?'-':((int)$r['geo_acc']).' m' ?></td>
          <td>
            <?php if (!empty($r['photo_path'])): ?>
              <a class="btn" href="<?= htmlspecialchars($ABS_BASE_H . '/photo.php?f=' . ltrim($r['photo_path'],'/'),ENT_QUOTES,'UTF-8') ?>" target="_blank">Lihat</a>
            <?php else: ?>-<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . "/_layout_bottom.php"; ?>
