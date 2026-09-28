<?php
// sales/sales_do_return.php
// Flow retur: CRM membuat request -> SCM menerima/verifikasi -> GOOD kembali ke stok -> non-GOOD masuk karantina WQS.

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/../stock/_stock_office_helper.php';
require_login();

if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.VIEW','SALES.EDIT','WQS.DO_TASKS']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','MANAGER','STAFF','CRM','WQS','SCM','ACT','FIN','BRANCH']);
}

$pdo = function_exists('db_pdo') ? db_pdo() : rmi_db_pdo();

// PATCH 2026-08-03: transaction guard.
// Beberapa proses helper/DDL dapat membuat transaksi sudah tidak aktif,
// sehingga commit() langsung memunculkan error: "There is no active transaction".
// Guard ini menjaga alur tetap aman: begin hanya saat belum aktif, commit/rollback hanya saat aktif.
function sdret_tx_begin(PDO $pdo): void {
    // FIX: sebelumnya fungsi ini memanggil dirinya sendiri (recursive) sehingga memory habis.
    // Yang benar: buka transaksi PDO hanya jika belum ada transaksi aktif.
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }
}
function sdret_tx_commit(PDO $pdo): void {
    // FIX: commit transaksi aktif, jangan recursive call.
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
}
function sdret_tx_rollback(PDO $pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function sdret_cols(PDO $pdo, string $table): array {
    try { return $pdo->query("SHOW COLUMNS FROM `" . str_replace('`','',$table) . "`")->fetchAll(PDO::FETCH_COLUMN, 0); }
    catch (Throwable $e) { return []; }
}
function sdret_has_col(PDO $pdo, string $table, string $col): bool {
    return in_array($col, sdret_cols($pdo, $table), true);
}
function sdret_actor(): string {
    if (function_exists('auth_user')) {
        $u = auth_user();
        foreach (['username','user_name','name','full_name'] as $k) {
            if (!empty($u[$k])) return (string)$u[$k];
        }
    }
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    return (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'SYSTEM');
}
function sdret_user_scope(): array {
    $u = function_exists('auth_user') ? auth_user() : [];
    $role = strtoupper(trim((string)($u['role'] ?? '')));
    $dept = strtoupper(trim((string)(($u['department'] ?? '') ?: ($u['level'] ?? ''))));
    $office = strtoupper(trim((string)($u['office_code'] ?? '')));
    $admin = in_array($role, ['SYS','SUPERADMIN','ADMIN','OWNER'], true);
    $branch = ($dept === 'BRANCH' || $role === 'BRANCH') && !$admin && $office !== '';
    $isCrm = $admin || $dept === 'CRM' || $role === 'CRM' || ($role === 'MANAGER' && $dept === 'CRM') || $branch;
    $isScm = $admin || $dept === 'SCM' || $role === 'SCM' || ($role === 'MANAGER' && $dept === 'SCM') || $branch;
    $isWqs = $admin || $dept === 'WQS' || $role === 'WQS' || ($role === 'MANAGER' && $dept === 'WQS') || $branch;
    return compact('role','dept','office','admin','branch','isCrm','isScm','isWqs');
}
function sdret_canonical_office(PDO $pdo, string $office): string {
    $office = strtoupper(trim($office));
    if (function_exists('rmi_office_canonical')) {
        try { return rmi_office_canonical($pdo, $office); } catch (Throwable $e) {}
    }
    return $office;
}
function sdret_same_office(PDO $pdo, string $a, string $b): bool {
    if (function_exists('rmi_office_same')) {
        try { return rmi_office_same($pdo, $a, $b); } catch (Throwable $e) {}
    }
    return strtoupper(trim($a)) === strtoupper(trim($b));
}
function sdret_item_classification(PDO $pdo, array $it): array {
    $bg = strtoupper(trim((string)($it['business_group'] ?? '')));
    $cat = strtoupper(trim((string)($it['category'] ?? '')));
    $rawCat = $cat;

    if ((!in_array($bg,['BMHP','UNIT_ACC'],true) || !in_array($cat,['BMHP','ALKES','AKSESORIS'],true)) && (int)($it['product_id'] ?? 0) > 0) {
        try {
            $cols = sdret_cols($pdo,'master_products');
            $sel = [];
            if (in_array('business_group',$cols,true)) $sel[]='business_group';
            if (in_array('category',$cols,true)) $sel[]='category';
            if ($sel) {
                $st=$pdo->prepare('SELECT '.implode(',', $sel).' FROM master_products WHERE id=? LIMIT 1');
                $st->execute([(int)$it['product_id']]);
                $mp=$st->fetch(PDO::FETCH_ASSOC) ?: [];
                $mbg=strtoupper(trim((string)($mp['business_group'] ?? '')));
                $mcat=strtoupper(trim((string)($mp['category'] ?? '')));
                if (!in_array($bg,['BMHP','UNIT_ACC'],true) && in_array($mbg,['BMHP','UNIT_ACC'],true)) $bg=$mbg;
                if (!in_array($cat,['BMHP','ALKES','AKSESORIS'],true) && in_array($mcat,['BMHP','ALKES','AKSESORIS'],true)) $cat=$mcat;
            }
        } catch (Throwable $e) {}
    }
    if (!in_array($bg,['BMHP','UNIT_ACC'],true)) {
        $bg = ($rawCat==='UNIT_ACC' || in_array($cat,['ALKES','AKSESORIS'],true)) ? 'UNIT_ACC' : 'BMHP';
    }
    if (!in_array($cat,['BMHP','ALKES','AKSESORIS'],true)) {
        // Jangan menebak klasifikasi legacy Unit ACC jika master belum direklasifikasi.
        $cat = $bg==='BMHP' ? 'BMHP' : ($rawCat!=='' ? $rawCat : 'UNIT_ACC');
    }
    return [$bg,$cat];
}
function sdret_return_item_classification(PDO $pdo, array $ri): array {
    $bg=strtoupper(trim((string)($ri['business_group'] ?? '')));
    $cat=strtoupper(trim((string)($ri['category'] ?? '')));
    if (in_array($bg,['BMHP','UNIT_ACC'],true) && in_array($cat,['BMHP','ALKES','AKSESORIS'],true)) return [$bg,$cat];
    $doItemId=(int)($ri['do_item_id'] ?? 0);
    if ($doItemId>0) {
        try {
            $st=$pdo->prepare('SELECT * FROM sales_do_items WHERE id=? LIMIT 1');
            $st->execute([$doItemId]);
            $di=$st->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($di) return sdret_item_classification($pdo,$di);
        } catch (Throwable $e) {}
    }
    return sdret_item_classification($pdo,$ri);
}
function sdret_do_scope_guard(PDO $pdo, array $do): void {
    $s = sdret_user_scope();
    if ($s['branch'] && !sdret_same_office($pdo, (string)($do['office_code'] ?? ''), (string)$s['office'])) {
        throw new Exception('Akses ditolak: DO bukan milik office Anda.');
    }
}
function sdret_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_code VARCHAR(50) NOT NULL,
        do_id INT NOT NULL,
        do_code VARCHAR(50) NULL,
        office_code VARCHAR(50) NULL,
        customer_code VARCHAR(100) NULL,
        return_type ENUM('FULL','PARTIAL') NOT NULL DEFAULT 'PARTIAL',
        reason TEXT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'return_requested',
        requested_by VARCHAR(100) NULL,
        requested_at DATETIME NULL,
        approved_by VARCHAR(100) NULL,
        approved_at DATETIME NULL,
        received_by VARCHAR(100) NULL,
        received_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        scm_note TEXT NULL,
        scm_completed_by VARCHAR(100) NULL,
        scm_completed_at DATETIME NULL,
        replacement_do_id INT NULL,
        replacement_created_at DATETIME NULL,
        commercial_effect VARCHAR(30) NULL,
        commercial_effect_set_by VARCHAR(100) NULL,
        commercial_effect_set_at DATETIME NULL,
        commercial_effect_reason TEXT NULL,
        UNIQUE KEY uq_return_code (return_code),
        KEY idx_do_id (do_id), KEY idx_status (status), KEY idx_office_code (office_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


    // Commercial effect is separate from physical stock movement.
    foreach ([
        ['commercial_effect','VARCHAR(30) NULL'],
        ['commercial_effect_set_by','VARCHAR(100) NULL'],
        ['commercial_effect_set_at','DATETIME NULL'],
        ['commercial_effect_reason','TEXT NULL'],
    ] as [$col,$ddl]) {
        if (!sdret_has_col($pdo,'sales_do_returns',$col)) {
            try { $pdo->exec("ALTER TABLE `sales_do_returns` ADD COLUMN `{$col}` {$ddl}"); }
            catch(Throwable $e) {}
        }
    }

    // V17: database legacy mungkin pernah memakai ENUM terbatas.
    // Pastikan REPLACEMENT dapat disimpan sebagai nilai classifier yang valid.
    try {
        $ci=$pdo->query("SHOW COLUMNS FROM sales_do_returns LIKE 'commercial_effect'")->fetch(PDO::FETCH_ASSOC);
        $ct=strtolower((string)($ci['Type']??''));
        if($ct!=='' && !str_starts_with($ct,'varchar')){
            $pdo->exec("ALTER TABLE sales_do_returns MODIFY commercial_effect VARCHAR(30) NULL");
        }
    } catch(Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_return_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        do_id INT NOT NULL,
        do_item_id INT NOT NULL,
        product_id INT NULL,
        sku VARCHAR(100) NULL,
        product_name VARCHAR(255) NULL,
        business_group VARCHAR(20) NULL,
        category VARCHAR(20) NULL,
        qty_do DECIMAL(18,2) NOT NULL DEFAULT 0,
        qty_return DECIMAL(18,2) NOT NULL DEFAULT 0,
        unit VARCHAR(50) NULL,
        lot_number VARCHAR(150) NULL,
        serial_number VARCHAR(150) NULL,
        condition_status ENUM('GOOD','DAMAGED','EXPIRED','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
        stock_returned TINYINT(1) NOT NULL DEFAULT 0,
        stock_returned_at DATETIME NULL,
        KEY idx_return_id (return_id), KEY idx_do_id (do_id), KEY idx_do_item_id (do_item_id), KEY idx_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_return_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        do_id INT NOT NULL,
        photo_type ENUM('CUSTOMER_PROOF','RECEIVED_WAREHOUSE','DAMAGED_PROOF','OTHER') NOT NULL DEFAULT 'OTHER',
        file_path VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NULL,
        uploaded_by VARCHAR(100) NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_return_id (return_id), KEY idx_do_id (do_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_return_corrections (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        do_id INT NOT NULL,
        old_status VARCHAR(50) NULL,
        action_code VARCHAR(60) NOT NULL,
        reason TEXT NOT NULL,
        corrected_by VARCHAR(100) NULL,
        corrected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_return_id (return_id),
        KEY idx_do_id (do_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_return_item_corrections (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        do_id INT NOT NULL,
        return_item_id INT NOT NULL,
        do_item_id INT NOT NULL,
        product_id INT NULL,
        sku VARCHAR(100) NULL,
        business_group VARCHAR(20) NULL,
        category VARCHAR(20) NULL,
        qty_removed DECIMAL(18,2) NOT NULL DEFAULT 0,
        condition_status VARCHAR(30) NULL,
        stock_was_returned TINYINT(1) NOT NULL DEFAULT 0,
        reason TEXT NOT NULL,
        corrected_by VARCHAR(100) NULL,
        corrected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_return_id (return_id),
        KEY idx_do_id (do_id),
        KEY idx_return_item_id (return_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wqs_stock_quarantine (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        do_id INT NOT NULL,
        product_id INT NULL,
        sku VARCHAR(100) NULL,
        qty DECIMAL(18,2) NOT NULL DEFAULT 0,
        reason VARCHAR(255) NULL,
        office_code VARCHAR(50) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'WAITING_QC',
        qc_note TEXT NULL,
        qc_result ENUM('GOOD','DAMAGED','EXPIRED','UNKNOWN') NULL,
        qc_by VARCHAR(100) NULL,
        qc_at DATETIME NULL,
        stock_released TINYINT(1) NOT NULL DEFAULT 0,
        stock_released_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_return_id (return_id), KEY idx_product_id (product_id), KEY idx_office_code (office_code), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        ['sales_do_return_items','business_group',"business_group VARCHAR(20) NULL AFTER product_name"],
        ['sales_do_return_items','category',"category VARCHAR(20) NULL AFTER business_group"],
        ['sales_do_return_item_corrections','business_group',"business_group VARCHAR(20) NULL AFTER sku"],
        ['sales_do_return_item_corrections','category',"category VARCHAR(20) NULL AFTER business_group"],
        ['sales_do','return_status',"return_status VARCHAR(50) NULL AFTER status"],
        ['sales_do','return_last_at',"return_last_at DATETIME NULL AFTER return_status"],
        ['sales_do_returns','scm_note',"scm_note TEXT NULL AFTER updated_at"],
        ['sales_do_returns','scm_completed_by',"scm_completed_by VARCHAR(100) NULL AFTER scm_note"],
        ['sales_do_returns','scm_completed_at',"scm_completed_at DATETIME NULL AFTER scm_completed_by"],
        ['sales_do_returns','replacement_do_id',"replacement_do_id INT NULL AFTER scm_completed_at"],
        ['sales_do_returns','replacement_created_at',"replacement_created_at DATETIME NULL AFTER replacement_do_id"],
        ['sales_do','replacement_for_do_id',"replacement_for_do_id INT NULL"],
        ['sales_do','replacement_return_id',"replacement_return_id INT NULL"],
        ['sales_do','replacement_created_at',"replacement_created_at DATETIME NULL"],
        ['wqs_stock_quarantine','status',"status VARCHAR(30) NOT NULL DEFAULT 'WAITING_QC'"],
        ['wqs_stock_quarantine','qc_note',"qc_note TEXT NULL"],
        ['wqs_stock_quarantine','qc_result',"qc_result ENUM('GOOD','DAMAGED','EXPIRED','UNKNOWN') NULL AFTER qc_note"],
        ['wqs_stock_quarantine','qc_by',"qc_by VARCHAR(100) NULL"],
        ['wqs_stock_quarantine','qc_at',"qc_at DATETIME NULL"],
        ['wqs_stock_quarantine','stock_released',"stock_released TINYINT(1) NOT NULL DEFAULT 0 AFTER qc_at"],
        ['wqs_stock_quarantine','stock_released_at',"stock_released_at DATETIME NULL AFTER stock_released"],
    ] as [$table,$col,$ddl]) {
        try { if (!sdret_has_col($pdo,$table,$col)) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}"); } catch (Throwable $e) {}
    }
}
function sdret_generate_code(PDO $pdo, string $office): string {
    $office = sdret_canonical_office($pdo, $office ?: 'RMI');
    $prefix = 'RTN-' . $office . '-' . date('ymd') . '-';
    $st = $pdo->prepare("SELECT COALESCE(MAX(CAST(RIGHT(return_code,3) AS UNSIGNED)),0) FROM sales_do_returns WHERE return_code LIKE ?");
    $st->execute([$prefix . '%']);
    return $prefix . str_pad((string)((int)$st->fetchColumn()+1), 3, '0', STR_PAD_LEFT);
}
function sdret_uploads(string $field, string $dirRel): array {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return [];
    $f = $_FILES[$field];
    if (!is_array($f['name'] ?? null)) {
        $f = ['name'=>[$f['name'] ?? 'file'],'tmp_name'=>[$f['tmp_name'] ?? ''],'error'=>[$f['error'] ?? UPLOAD_ERR_NO_FILE],'size'=>[$f['size'] ?? 0]];
    }
    $base = realpath(__DIR__ . '/..');
    if ($base === false) return [];
    $dirAbs = $base . '/' . trim($dirRel,'/');
    if (!is_dir($dirAbs)) @mkdir($dirAbs, 0777, true);
    $allow = ['jpg','jpeg','png','webp','pdf'];
    $saved = [];
    foreach ($f['name'] as $i=>$name) {
        if ((int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp = (string)($f['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;
        $name = (string)($name ?: 'file');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allow, true)) continue;
        $baseName = function_exists('safe_filename') ? safe_filename($name) : preg_replace('/[^a-zA-Z0-9._-]/','_', $name);
        $stem = pathinfo($baseName, PATHINFO_FILENAME) ?: 'retur';
        $fname = $stem . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        if (@move_uploaded_file($tmp, $dirAbs . '/' . $fname)) $saved[] = ['path'=>'/' . trim($dirRel,'/') . '/' . $fname,'original'=>$name];
    }
    return $saved;
}
function sdret_save_photos(PDO $pdo, int $returnId, int $doId, string $type, array $files): void {
    if (!$files) return;
    $st = $pdo->prepare("INSERT INTO sales_do_return_photos(return_id,do_id,photo_type,file_path,original_name,uploaded_by) VALUES(?,?,?,?,?,?)");
    foreach ($files as $f) if (!empty($f['path'])) $st->execute([$returnId,$doId,$type,$f['path'],$f['original'] ?? '',sdret_actor()]);
}
function sdret_sync_global_stock(PDO $pdo, int $productId): void {
    if ($productId <= 0) return;
    try {
        $pdo->prepare("INSERT INTO wqs_stock(product_id,stock_qty,updated_at)
            SELECT ?,COALESCE(SUM(stock_qty),0),NOW() FROM wqs_stock_by_office WHERE product_id=?
            ON DUPLICATE KEY UPDATE stock_qty=VALUES(stock_qty),updated_at=VALUES(updated_at)")->execute([$productId,$productId]);
    } catch (Throwable $e) {}
}
function sdret_add_stock(PDO $pdo, int $productId, float $qty, string $officeCode): void {
    if ($productId <= 0 || $qty <= 0 || trim($officeCode) === '') throw new Exception('Data stok retur tidak valid.');
    $officeCode = sdret_canonical_office($pdo, $officeCode);
    $cols = sdret_cols($pdo, 'wqs_stock_by_office');
    if (!$cols) throw new Exception('Tabel wqs_stock_by_office belum tersedia.');
    $hasId = in_array('id',$cols,true); $hasUpdatedAt = in_array('updated_at',$cols,true);
    $setUpd = $hasUpdatedAt ? ', updated_at=NOW()' : '';

    $params = [];
    if (function_exists('rmi_office_in_sql')) {
        $officeWhere = rmi_office_in_sql($pdo, 'office_code', $officeCode, $params);
    } else {
        $officeWhere = 'UPPER(TRIM(office_code))=?'; $params[] = $officeCode;
    }
    if ($hasId) {
        $st = $pdo->prepare("SELECT id FROM wqs_stock_by_office WHERE product_id=? AND {$officeWhere} ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $st->execute(array_merge([$productId],$params));
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE wqs_stock_by_office SET stock_qty=COALESCE(stock_qty,0)+?{$setUpd} WHERE id=?")->execute([$qty,$id]);
        } else {
            $sql = "INSERT INTO wqs_stock_by_office(product_id,office_code,stock_qty" . ($hasUpdatedAt?',updated_at':'') . ") VALUES(?,?,?" . ($hasUpdatedAt?',NOW()':'') . ")";
            $pdo->prepare($sql)->execute([$productId,$officeCode,$qty]);
        }
    } else {
        $up = $pdo->prepare("UPDATE wqs_stock_by_office SET stock_qty=COALESCE(stock_qty,0)+?{$setUpd} WHERE product_id=? AND {$officeWhere} LIMIT 1");
        $up->execute(array_merge([$qty,$productId],$params));
        if ($up->rowCount() < 1) throw new Exception('Baris stok office tidak ditemukan untuk pengembalian retur.');
    }
    sdret_sync_global_stock($pdo,$productId);
}
function sdret_remove_stock(PDO $pdo, int $productId, float $qty, string $officeCode): void {
    if ($productId <= 0 || $qty <= 0 || trim($officeCode) === '') {
        throw new Exception('Data koreksi stok retur tidak valid.');
    }
    $officeCode = sdret_canonical_office($pdo, $officeCode);
    $cols = sdret_cols($pdo, 'wqs_stock_by_office');
    if (!$cols) throw new Exception('Tabel wqs_stock_by_office belum tersedia.');
    $hasId = in_array('id', $cols, true);
    $hasUpdatedAt = in_array('updated_at', $cols, true);
    $setUpd = $hasUpdatedAt ? ', updated_at=NOW()' : '';

    $params = [];
    if (function_exists('rmi_office_in_sql')) {
        $officeWhere = rmi_office_in_sql($pdo, 'office_code', $officeCode, $params);
    } else {
        $officeWhere = 'UPPER(TRIM(office_code))=?';
        $params[] = $officeCode;
    }

    if ($hasId) {
        $st = $pdo->prepare("SELECT id, COALESCE(stock_qty,0) stock_qty FROM wqs_stock_by_office
                             WHERE product_id=? AND {$officeWhere} AND COALESCE(stock_qty,0)>0
                             ORDER BY id ASC FOR UPDATE");
        $st->execute(array_merge([$productId], $params));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $available = array_sum(array_map(static fn($r)=>(float)($r['stock_qty'] ?? 0), $rows));
        if ($available + 0.00001 < $qty) {
            throw new Exception('Koreksi kondisi gagal: stok office yang tersedia lebih kecil dari qty retur yang sebelumnya ditambahkan.');
        }
        $remaining = $qty;
        foreach ($rows as $r) {
            if ($remaining <= 0.00001) break;
            $take = min((float)$r['stock_qty'], $remaining);
            if ($take <= 0) continue;
            $up = $pdo->prepare("UPDATE wqs_stock_by_office SET stock_qty=stock_qty-?{$setUpd} WHERE id=? AND stock_qty>=?");
            $up->execute([$take, (int)$r['id'], $take]);
            if ($up->rowCount() < 1) throw new Exception('Stok berubah saat koreksi kondisi retur. Silakan ulangi.');
            $remaining -= $take;
        }
    } else {
        $st = $pdo->prepare("SELECT COALESCE(stock_qty,0) FROM wqs_stock_by_office WHERE product_id=? AND {$officeWhere} LIMIT 1 FOR UPDATE");
        $st->execute(array_merge([$productId], $params));
        $available = (float)($st->fetchColumn() ?: 0);
        if ($available + 0.00001 < $qty) throw new Exception('Koreksi kondisi gagal karena stok office tidak mencukupi.');
        $up = $pdo->prepare("UPDATE wqs_stock_by_office SET stock_qty=stock_qty-?{$setUpd} WHERE product_id=? AND {$officeWhere} AND stock_qty>=? LIMIT 1");
        $up->execute(array_merge([$qty, $productId], $params, [$qty]));
        if ($up->rowCount() < 1) throw new Exception('Koreksi stok retur gagal.');
    }
    sdret_sync_global_stock($pdo, $productId);
}

function sdret_already_returned_qty(PDO $pdo, int $doItemId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(i.qty_return),0)
        FROM sales_do_return_items i JOIN sales_do_returns r ON r.id=i.return_id
        WHERE i.do_item_id=? AND r.status NOT IN ('cancelled','rejected')");
    $st->execute([$doItemId]);
    return (float)($st->fetchColumn() ?: 0);
}


// Menentukan progres retur berdasarkan qty aktual, bukan hanya return_status.
// Ini menjaga PARTIAL return tetap bisa melanjutkan pengiriman, sedangkan FULL return
// otomatis keluar dari live shipment/tracker tanpa mengubah status workflow utama DO.
function sdret_return_progress(PDO $pdo, int $doId): array {
    $stDo = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM sales_do_items WHERE do_id=?");
    $stDo->execute([$doId]);
    $qtyDo = (float)($stDo->fetchColumn() ?: 0);

    $stRet = $pdo->prepare("SELECT COALESCE(SUM(i.qty_return),0)
        FROM sales_do_return_items i
        JOIN sales_do_returns r ON r.id=i.return_id
        WHERE r.do_id=?
          AND LOWER(COALESCE(r.status,'')) IN ('return_scm_completed','return_completed','completed','closed')");
    $stRet->execute([$doId]);
    $qtyReturned = (float)($stRet->fetchColumn() ?: 0);

    $remaining = max(0.0, $qtyDo - $qtyReturned);
    $isFull = ($qtyDo > 0.00001 && $qtyReturned + 0.00001 >= $qtyDo);
    return [
        'qty_do' => $qtyDo,
        'qty_returned' => $qtyReturned,
        'remaining' => $remaining,
        'is_full' => $isFull,
    ];
}
/**
 * Ringkasan nilai retur untuk guard komersial.
 * Nilai mengikuti item DO asal (net/subtotal), bukan grand total header.
 */
function sdret_return_value_summary(PDO $pdo, int $returnId): array {
    $out=['qty_do'=>0.0,'qty_return'=>0.0,'return_net'=>0.0,'line_count'=>0];
    if($returnId<=0) return $out;
    try{
        $st=$pdo->prepare("SELECT
                COALESCE(SUM(COALESCE(ri.qty_do,di.qty,0)),0) qty_do,
                COALESCE(SUM(COALESCE(ri.qty_return,0)),0) qty_return,
                COALESCE(SUM(
                    COALESCE(ri.qty_return,0) *
                    (COALESCE(di.subtotal,0) / NULLIF(di.qty,0))
                ),0) return_net,
                COUNT(*) line_count
            FROM sales_do_return_items ri
            JOIN sales_do_items di
              ON di.id=ri.do_item_id AND di.do_id=ri.do_id
            WHERE ri.return_id=?");
        $st->execute([$returnId]);
        $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['qty_do']=(float)($r['qty_do']??0);
        $out['qty_return']=(float)($r['qty_return']??0);
        $out['return_net']=(float)($r['return_net']??0);
        $out['line_count']=(int)($r['line_count']??0);
    }catch(Throwable $e){}
    return $out;
}

/**
 * Guard human-error klasifikasi komersial.
 * OPERATIONAL_ONLY sangat berisiko bila catatan justru menyebut revisi/pengurangan
 * QTY/PO/tagihan. Blok kecuali SYS melakukan override eksplisit.
 */
function sdret_commercial_effect_guard(array $scope, string $effect, string $reason, array $summary, array $post): void {
    if(!in_array($effect,['REVERSAL','OPERATIONAL_ONLY','REPLACEMENT'],true)){
        throw new Exception('Pengaruh komersial retur tidak valid.');
    }
    if(empty($post['commercial_effect_confirm'])){
        throw new Exception('Konfirmasi dampak komersial wajib dicentang sebelum finalisasi.');
    }
    if((float)($summary['return_net']??0)<=0 || (float)($summary['qty_return']??0)<=0){
        throw new Exception('Nilai/qty retur tidak valid. Periksa item retur sebelum menentukan pengaruh komersial.');
    }

    if($effect==='OPERATIONAL_ONLY'){
        if(empty($post['billing_unchanged_confirm'])){
            throw new Exception('OPERATIONAL_ONLY hanya boleh dipilih bila PO/invoice/tagihan customer tetap sama. Centang konfirmasi billing unchanged.');
        }
        $txt=strtoupper(trim($reason));
        $valueChangePattern='/((REVISI|UBAH|KURANG|PENGURANGAN|POTONG|BATAL|CANCEL|KOREKSI).*(QTY|QUANTITY|PO|PESANAN|TAGIHAN|INVOICE))|((QTY|QUANTITY|PO|PESANAN|TAGIHAN|INVOICE).*(REVISI|UBAH|KURANG|PENGURANGAN|POTONG|BATAL|CANCEL|KOREKSI))/u';
        if(preg_match($valueChangePattern,$txt)){
            $isAdmin=!empty($scope['admin']);
            $override=!empty($post['operational_override_confirm']);
            if(!$isAdmin || !$override){
                throw new Exception('Catatan mengindikasikan perubahan QTY/PO/tagihan customer. Gunakan KURANGI PENJUALAN (REVERSAL). Jika billing memang tetap sama, hanya SYS yang boleh override dengan konfirmasi khusus.');
            }
        }
    }

    if($effect==='REPLACEMENT' && empty($post['replacement_commit_confirm'])){
        throw new Exception('REPLACEMENT wajib disertai konfirmasi bahwa DO pengganti akan dibuat dari retur ini, bukan sebagai order/penjualan baru.');
    }
}

function sdret_status_label(string $status): string {
    return match(strtolower($status)) {
        'return_requested' => 'Menunggu SCM',
        'return_stocked' => 'Menunggu Selesai SCM',
        'return_scm_completed' => 'Selesai SCM',
        'cancelled' => 'Dibatalkan',
        default => strtoupper($status),
    };
}

function sdret_replacement_do(PDO $pdo, int $returnId): array {
    if ($returnId <= 0) return [];
    try {
        $st=$pdo->prepare("SELECT d.id,d.do_code,d.status,d.do_date
                           FROM sales_do d
                           WHERE d.replacement_return_id=?
                             AND LOWER(COALESCE(d.status,'')) NOT IN ('cancelled','canceled','rejected','reject','void','voided')
                           ORDER BY d.id DESC LIMIT 1");
        $st->execute([$returnId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

sdret_ensure_schema($pdo);
$scope = sdret_user_scope();
$success=''; $error='';
$do_id=(int)($_GET['do_id'] ?? $_GET['id'] ?? $_POST['do_id'] ?? 0);
$return_id=(int)($_GET['return_id'] ?? $_POST['return_id'] ?? 0);
$queue=strtolower(trim((string)($_GET['queue'] ?? '')));

// Audit exact FINAL returns yang saat ini mengurangi Executive Summary.
$finalReturnAudit=[];
if(!empty($scope['admin'])){
    try{
        $q=$pdo->query("
            SELECT
                r.id return_id,
                r.return_code,
                r.do_id,
                r.do_code,
                r.return_type,
                r.status,
                r.scm_completed_at,
                r.replacement_do_id,
                r.commercial_effect,
                COALESCE(SUM(
                    ri.qty_return * (COALESCE(di.subtotal,0)/NULLIF(di.qty,0))
                ),0) return_net,
                COUNT(*) item_count
            FROM sales_do_returns r
            JOIN sales_do_return_items ri
              ON ri.return_id=r.id AND ri.do_id=r.do_id
            JOIN sales_do_items di
              ON di.id=ri.do_item_id AND di.do_id=r.do_id
            WHERE LOWER(TRIM(COALESCE(r.status,'')))='return_scm_completed'
            GROUP BY r.id,r.return_code,r.do_id,r.do_code,r.return_type,r.status,
                     r.scm_completed_at,r.replacement_do_id,r.commercial_effect
            ORDER BY r.scm_completed_at DESC,r.id DESC
            LIMIT 100
        ");
        $finalReturnAudit=$q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }catch(Throwable $e){
        $finalReturnAudit=[];
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('require_post')) require_post();
        // Samakan dengan pola CSRF ERP yang dipakai sales_do.php:
        // verify_csrf() membaca csrf_token dari POST/session secara internal.
        // Jangan kirim token sebagai argumen karena helper project tidak memakai signature itu.
        if (function_exists('verify_csrf')) verify_csrf();
        $action=(string)($_POST['action'] ?? '');

        if ($action === 'create_return') {
            if (!$scope['isCrm']) throw new Exception('Hanya CRM/Manager CRM/Admin atau user cabang terkait yang dapat membuat pengajuan retur.');
            $st=$pdo->prepare("SELECT d.*,c.customers_name FROM sales_do d LEFT JOIN master_customers c ON c.customers_code=d.customers_code WHERE d.id=? LIMIT 1");
            $st->execute([$do_id]); $do=$st->fetch(PDO::FETCH_ASSOC);
            if (!$do) throw new Exception('DO tidak ditemukan.');
            sdret_do_scope_guard($pdo,$do);
            $status=strtolower((string)($do['status'] ?? ''));
            if (!in_array($status,['on_delivery','delivered','wait_payment','paid'],true)) {
                throw new Exception('Retur hanya digunakan setelah barang mulai dikirim. Jika masih READY SCM, gunakan proses Revisi/Kembalikan ke WQS.');
            }
            $reason=trim((string)($_POST['reason'] ?? ''));
            if ($reason==='') throw new Exception('Alasan retur wajib diisi.');
            $qtys=(array)($_POST['qty_return'] ?? []);
            $itemsSt=$pdo->prepare("SELECT * FROM sales_do_items WHERE do_id=? ORDER BY line_no ASC,id ASC");
            $itemsSt->execute([$do_id]); $items=$itemsSt->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) throw new Exception('Item DO tidak ditemukan.');

            sdret_tx_begin($pdo);
            $code=sdret_generate_code($pdo,(string)($do['office_code'] ?? ''));
            $office=sdret_canonical_office($pdo,(string)($do['office_code'] ?? ''));
            $ins=$pdo->prepare("INSERT INTO sales_do_returns(return_code,do_id,do_code,office_code,customer_code,return_type,reason,status,requested_by,requested_at) VALUES(?,?,?,?,?,'PARTIAL',?,'return_requested',?,NOW())");
            $ins->execute([$code,$do_id,$do['do_code'] ?? '',$office,$do['customers_code'] ?? '',$reason,sdret_actor()]);
            $return_id=(int)$pdo->lastInsertId();
            $insIt=$pdo->prepare("INSERT INTO sales_do_return_items(return_id,do_id,do_item_id,product_id,sku,product_name,business_group,category,qty_do,qty_return,unit,lot_number,serial_number,condition_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'UNKNOWN')");
            $totalLines=0; $sumRet=0.0; $sumAvailable=0.0;
            foreach ($items as $it) {
                $iid=(int)$it['id']; $qtyDo=(float)($it['qty'] ?? 0);
                $already=sdret_already_returned_qty($pdo,$iid); $available=max(0,$qtyDo-$already); $sumAvailable += $available;
                $qtyRet=(float)str_replace(',','.',(string)($qtys[$iid] ?? 0));
                if ($qtyRet<=0) continue;
                if ($qtyRet>$available+0.00001) throw new Exception('Qty retur SKU ' . ($it['sku'] ?? '') . ' melebihi sisa yang dapat diretur. Sisa: ' . $available);
                $lot=trim((string)($it['serial_lot'] ?? $it['lot_number'] ?? $it['lot_no'] ?? ''));
                [$itemBg,$itemCat]=sdret_item_classification($pdo,$it);
                $insIt->execute([$return_id,$do_id,$iid,(int)($it['product_id'] ?? 0),(string)($it['sku'] ?? ''),(string)($it['products_name'] ?? ''),$itemBg,$itemCat,$qtyDo,$qtyRet,(string)($it['unit'] ?? ''),$lot,$lot]);
                $totalLines++; $sumRet += $qtyRet;
            }
            if ($totalLines<=0) throw new Exception('Minimal pilih satu item dan isi qty retur.');
            $returnType=($sumAvailable>0 && $sumRet+0.00001 >= $sumAvailable)?'FULL':'PARTIAL';
            $pdo->prepare("UPDATE sales_do_returns SET return_type=?,updated_at=NOW() WHERE id=?")->execute([$returnType,$return_id]);
            $pdo->prepare("UPDATE sales_do SET return_status='return_requested',return_last_at=NOW() WHERE id=?")->execute([$do_id]);
            sdret_tx_commit($pdo);
            sdret_save_photos($pdo,$return_id,$do_id,'CUSTOMER_PROOF',sdret_uploads('return_photos','uploads/sales_returns'));
            $success='Retur dibuat: '.$code.'. Menunggu verifikasi dan penerimaan SCM.';
        }

        if ($action === 'set_return_commercial_effect') {
            $scopeNow=sdret_user_scope();
            if(!$scopeNow['admin']) throw new Exception('Klasifikasi pengaruh komersial retur FINAL hanya boleh dilakukan SYS/Admin.');

            $rid=(int)($_POST['return_id'] ?? 0);
            $effect=strtoupper(trim((string)($_POST['commercial_effect'] ?? '')));
            $reason=trim((string)($_POST['commercial_effect_reason'] ?? ''));
            if($rid<=0) throw new Exception('Return ID tidak valid.');
            if(!in_array($effect,['REVERSAL','OPERATIONAL_ONLY','REPLACEMENT'],true)){
                throw new Exception('Pengaruh komersial tidak valid.');
            }
            if(mb_strlen($reason)<8) throw new Exception('Alasan klasifikasi wajib diisi minimal 8 karakter.');

            $summary=sdret_return_value_summary($pdo,$rid);
            sdret_commercial_effect_guard($scopeNow,$effect,$reason,$summary,$_POST);

            $q=$pdo->prepare("SELECT id,do_id,status,commercial_effect FROM sales_do_returns WHERE id=? LIMIT 1");
            $q->execute([$rid]);
            $rr=$q->fetch(PDO::FETCH_ASSOC);
            if(!$rr) throw new Exception('Retur tidak ditemukan.');
            if(strtolower(trim((string)$rr['status']))!=='return_scm_completed'){
                throw new Exception('Hanya retur FINAL SCM yang dapat diklasifikasi.');
            }

            sdret_tx_begin($pdo);
            $pdo->prepare("UPDATE sales_do_returns
                           SET commercial_effect=?,
                               commercial_effect_set_by=?,
                               commercial_effect_set_at=NOW(),
                               commercial_effect_reason=?,
                               scm_note=CONCAT(COALESCE(scm_note,''), '\\n[COMMERCIAL EFFECT] ', ?, ' — ', ?),
                               updated_at=NOW()
                           WHERE id=?")
                ->execute([$effect,sdret_actor(),$reason,$effect,$reason,$rid]);

            try{
                if(function_exists('master_audit')){
                    master_audit($pdo,'sales_do_returns','sales_do_returns','SET_COMMERCIAL_EFFECT',
                                 $rid,(string)$rid,
                                 "Return {$rid} commercial_effect={$effect}",
                                 ['reason'=>$reason,'old'=>$rr['commercial_effect']??null,'new'=>$effect]);
                }
            }catch(Throwable $e){}

            sdret_tx_commit($pdo);
            $do_id=(int)$rr['do_id'];

            // POST/Redirect/GET: tabel audit pada file legacy dimuat sebelum POST,
            // sehingga tanpa redirect layar dapat masih menampilkan nilai lama walau DB sudah berubah.
            if(!headers_sent()){
                header('Location: sales_do_return.php?do_id='.(int)$do_id.'&ce_updated='.rawurlencode($effect));
                exit;
            }
            $success='Pengaruh komersial retur diperbarui menjadi '.$effect.'. Refresh halaman bila tabel audit belum berubah.';
        }

        if ($action === 'cancel_final_return_operator_error') {
            $scopeNow=sdret_user_scope();
            if(!$scopeNow['admin']) throw new Exception('Pembatalan retur FINAL salah operator hanya boleh dilakukan SYS/Admin.');

            $rid=(int)($_POST['return_id'] ?? 0);
            $reason=trim((string)($_POST['correction_reason'] ?? ''));
            $confirmed=!empty($_POST['correction_confirm']);
            if($rid<=0) throw new Exception('Return ID tidak valid.');
            if(!$confirmed) throw new Exception('Konfirmasi pembatalan retur wajib dicentang.');
            if(mb_strlen($reason)<8) throw new Exception('Alasan koreksi wajib diisi minimal 8 karakter.');

            $st=$pdo->prepare("SELECT r.*,d.office_code AS do_office,d.do_code AS current_do_code
                               FROM sales_do_returns r
                               JOIN sales_do d ON d.id=r.do_id
                               WHERE r.id=? LIMIT 1");
            $st->execute([$rid]);
            $rr=$st->fetch(PDO::FETCH_ASSOC);
            if(!$rr) throw new Exception('Retur tidak ditemukan.');
            if(strtolower(trim((string)($rr['status']??'')))!=='return_scm_completed'){
                throw new Exception('Hanya retur status Selesai SCM yang dapat dikoreksi.');
            }
            if((int)($rr['replacement_do_id']??0)>0){
                throw new Exception('Retur mempunyai replacement_do_id. Jangan dibatalkan dari fitur ini.');
            }

            sdret_tx_begin($pdo);

            $itemsQ=$pdo->prepare("SELECT * FROM sales_do_return_items WHERE return_id=? ORDER BY id FOR UPDATE");
            $itemsQ->execute([$rid]);
            $items=$itemsQ->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if(!$items) throw new Exception('Retur FINAL tidak mempunyai item.');

            $office=(string)(($rr['office_code']??'') ?: ($rr['do_office']??''));

            foreach($items as $it){
                $qty=(float)($it['qty_return']??0);
                $pid=(int)($it['product_id']??0);
                $cond=strtoupper(trim((string)($it['condition_status']??'UNKNOWN')));
                $stockReturned=(int)($it['stock_returned']??0)===1;
                if($qty<=0 || $pid<=0 || !$stockReturned) continue;

                if($cond==='GOOD'){
                    sdret_remove_stock($pdo,$pid,$qty,$office);
                } else {
                    $qc=$pdo->prepare("SELECT id,status,stock_released
                                       FROM wqs_stock_quarantine
                                       WHERE return_id=? AND product_id=?
                                       ORDER BY id DESC");
                    $qc->execute([$rid,$pid]);
                    foreach($qc->fetchAll(PDO::FETCH_ASSOC) ?: [] as $qr){
                        $qs=strtoupper(trim((string)($qr['status']??'')));
                        if($qs==='QC_COMPLETED' || (int)($qr['stock_released']??0)===1){
                            throw new Exception('Pembatalan diblok: item non-GOOD sudah QC/release WQS.');
                        }
                    }
                    $pdo->prepare("DELETE FROM wqs_stock_quarantine WHERE return_id=? AND product_id=?")
                        ->execute([$rid,$pid]);
                }
            }

            $pdo->prepare("INSERT INTO sales_do_return_corrections
                           (return_id,do_id,old_status,action_code,reason,corrected_by)
                           VALUES(?,?,?,?,?,?)")
                ->execute([$rid,(int)$rr['do_id'],(string)$rr['status'],
                           'CANCEL_FINAL_OPERATOR_ERROR',$reason,sdret_actor()]);

            $pdo->prepare("UPDATE sales_do_returns
                           SET status='cancelled',
                               scm_note=CONCAT(COALESCE(scm_note,''), '\n[SYS CORRECTION] FINAL return dibatalkan: ', ?),
                               updated_at=NOW()
                           WHERE id=?")
                ->execute([$reason,$rid]);

            $pdo->prepare("UPDATE sales_do_return_items
                           SET stock_returned=0,stock_returned_at=NULL
                           WHERE return_id=?")
                ->execute([$rid]);

            $latest=$pdo->prepare("SELECT status
                                   FROM sales_do_returns
                                   WHERE do_id=? AND id<>?
                                     AND LOWER(COALESCE(status,'')) NOT IN ('cancelled','rejected')
                                   ORDER BY COALESCE(updated_at,created_at) DESC,id DESC
                                   LIMIT 1");
            $latest->execute([(int)$rr['do_id'],$rid]);
            $latestStatus=(string)($latest->fetchColumn() ?: '');

            if($latestStatus!==''){
                $pdo->prepare("UPDATE sales_do SET return_status=?,return_last_at=NOW() WHERE id=?")
                    ->execute([$latestStatus,(int)$rr['do_id']]);
            } else {
                $pdo->prepare("UPDATE sales_do SET return_status=NULL,return_last_at=NOW() WHERE id=?")
                    ->execute([(int)$rr['do_id']]);
            }

            sdret_tx_commit($pdo);
            $success='Retur FINAL salah operator berhasil dibatalkan. History tetap ada dan dampak stok dibalik.';
            $do_id=(int)$rr['do_id'];
            $return_id=0;
        }

        if ($action === 'correct_final_return_item') {
            $scopeNow = sdret_user_scope();
            if (!$scopeNow['admin']) {
                throw new Exception('Koreksi item retur FINAL hanya boleh dilakukan SYS/Admin.');
            }

            $returnItemId=(int)($_POST['return_item_id'] ?? 0);
            $reason=trim((string)($_POST['correction_reason'] ?? ''));
            $confirmed=!empty($_POST['correction_confirm']);
            if($returnItemId<=0) throw new Exception('Item retur yang akan dikoreksi tidak valid.');
            if(!$confirmed) throw new Exception('Konfirmasi koreksi wajib dicentang.');
            if(mb_strlen($reason)<8) throw new Exception('Alasan koreksi wajib diisi minimal 8 karakter.');

            $q=$pdo->prepare("
                SELECT
                    ri.*,
                    r.status AS return_status,
                    r.office_code AS return_office,
                    r.do_id AS original_do_id,
                    d.office_code AS do_office
                FROM sales_do_return_items ri
                JOIN sales_do_returns r ON r.id=ri.return_id
                JOIN sales_do d ON d.id=r.do_id
                WHERE ri.id=?
                LIMIT 1
            ");
            $q->execute([$returnItemId]);
            $it=$q->fetch(PDO::FETCH_ASSOC);
            if(!$it) throw new Exception('Item retur tidak ditemukan.');

            $returnId=(int)$it['return_id'];
            $origDoId=(int)$it['original_do_id'];
            $retStatus=strtolower(trim((string)$it['return_status']));
            if($retStatus!=='return_scm_completed'){
                throw new Exception('Fitur ini hanya untuk koreksi data operator pada retur yang sudah FINAL SCM.');
            }

            // Harus menyisakan minimal satu item return agar tidak mengubah keseluruhan transaksi return menjadi kosong.
            $cnt=$pdo->prepare("SELECT COUNT(*) FROM sales_do_return_items WHERE return_id=?");
            $cnt->execute([$returnId]);
            if((int)$cnt->fetchColumn()<=1){
                throw new Exception('Koreksi diblok: item ini adalah satu-satunya item retur. Jika seluruh retur salah, gunakan proses pembatalan retur khusus.');
            }

            // Pastikan item belum pernah dikoreksi sebelumnya.
            $dup=$pdo->prepare("SELECT COUNT(*) FROM sales_do_return_item_corrections WHERE return_item_id=?");
            $dup->execute([$returnItemId]);
            if((int)$dup->fetchColumn()>0){
                throw new Exception('Item retur ini sudah pernah dikoreksi.');
            }

            $qty=(float)($it['qty_return'] ?? 0);
            $pid=(int)($it['product_id'] ?? 0);
            $cond=strtoupper(trim((string)($it['condition_status'] ?? 'UNKNOWN')));
            $stockReturned=(int)($it['stock_returned'] ?? 0)===1;
            $office=(string)(($it['return_office'] ?? '') ?: ($it['do_office'] ?? ''));

            if($qty<=0) throw new Exception('Qty retur item tidak valid.');

            sdret_tx_begin($pdo);

            /*
             * Balik dampak stok dari item yang ternyata TIDAK benar-benar diretur.
             * GOOD: sebelumnya stok ditambah -> sekarang stok dikurangi kembali.
             * Non-GOOD: hapus antrean karantina jika belum QC selesai.
             */
            if($stockReturned){
                if($cond==='GOOD'){
                    sdret_remove_stock($pdo,$pid,$qty,$office);
                } else {
                    $qc=$pdo->prepare("
                        SELECT id,status,stock_released
                        FROM wqs_stock_quarantine
                        WHERE return_id=? AND product_id=? AND qty=?
                        ORDER BY id DESC
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $qc->execute([$returnId,$pid,$qty]);
                    $qr=$qc->fetch(PDO::FETCH_ASSOC);

                    if($qr){
                        $qs=strtoupper(trim((string)($qr['status'] ?? '')));
                        if($qs==='QC_COMPLETED'){
                            throw new Exception(
                                'Koreksi diblok: item non-GOOD sudah menyelesaikan QC WQS. '.
                                'Lakukan koreksi stok/QC terpisah agar audit tidak rusak.'
                            );
                        }
                        $pdo->prepare("DELETE FROM wqs_stock_quarantine WHERE id=?")
                            ->execute([(int)$qr['id']]);
                    }
                }
            }

            // Simpan audit SEBELUM menghapus row aktif.
            [$corrBg,$corrCat]=sdret_return_item_classification($pdo,$it);
            $ins=$pdo->prepare("
                INSERT INTO sales_do_return_item_corrections
                (return_id,do_id,return_item_id,do_item_id,product_id,sku,business_group,category,qty_removed,
                 condition_status,stock_was_returned,reason,corrected_by)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins->execute([
                $returnId,
                $origDoId,
                $returnItemId,
                (int)($it['do_item_id'] ?? 0),
                $pid,
                (string)($it['sku'] ?? ''),
                $corrBg,
                $corrCat,
                $qty,
                $cond,
                $stockReturned ? 1 : 0,
                $reason,
                sdret_actor(),
            ]);

            // Hapus item yang memang salah dipilih operator dari ledger return aktif.
            $pdo->prepare("DELETE FROM sales_do_return_items WHERE id=? AND return_id=?")
                ->execute([$returnItemId,$returnId]);

            // Recompute FULL/PARTIAL setelah koreksi.
            $progress=sdret_return_progress($pdo,$origDoId);
            $newType=!empty($progress['is_full']) ? 'FULL' : 'PARTIAL';
            $pdo->prepare("
                UPDATE sales_do_returns
                SET return_type=?,
                    scm_note=CONCAT(COALESCE(scm_note,''), '\n[CORRECTION] ', ?),
                    updated_at=NOW()
                WHERE id=?
            ")->execute([$newType,'Item retur salah operator dikoreksi oleh '.sdret_actor().': '.$reason,$returnId]);

            sdret_tx_commit($pdo);

            $success='Koreksi item retur berhasil. Item '.(string)($it['sku'] ?? '').
                     ' qty '.rtrim(rtrim(number_format($qty,2,'.',''),'0'),'.').
                     ' dihapus dari return FINAL dan dampak stoknya dibalik.';

            $do_id=$origDoId;
        }

        if ($action === 'receive_return') {
            if (!$scope['isScm']) throw new Exception('Hanya SCM/Admin atau user cabang terkait yang dapat menerima dan memproses stok retur.');
            $st=$pdo->prepare("SELECT r.*,d.office_code AS do_office FROM sales_do_returns r JOIN sales_do d ON d.id=r.do_id WHERE r.id=? LIMIT 1");
            $st->execute([$return_id]); $ret=$st->fetch(PDO::FETCH_ASSOC);
            if (!$ret) throw new Exception('Retur tidak ditemukan.');
            sdret_do_scope_guard($pdo,['office_code'=>$ret['office_code'] ?: $ret['do_office']]);
            if (($ret['status'] ?? '') === 'return_stocked') throw new Exception('Retur ini sudah diproses. Stok tidak ditambahkan ulang.');
            $finalConds=(array)($_POST['final_condition'] ?? []);

            sdret_tx_begin($pdo);
            $itemsSt=$pdo->prepare("SELECT * FROM sales_do_return_items WHERE return_id=? FOR UPDATE");
            $itemsSt->execute([$return_id]); $items=$itemsSt->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) throw new Exception('Item retur tidak ditemukan.');
            foreach ($items as $it) {
                if ((int)($it['stock_returned'] ?? 0)===1) continue;
                $itemId=(int)$it['id']; $qty=(float)($it['qty_return'] ?? 0); $pid=(int)($it['product_id'] ?? 0);
                $cond=strtoupper(trim((string)($finalConds[$itemId] ?? $it['condition_status'] ?? 'UNKNOWN')));
                if (!in_array($cond,['GOOD','DAMAGED','EXPIRED','UNKNOWN'],true)) $cond='UNKNOWN';
                $pdo->prepare("UPDATE sales_do_return_items SET condition_status=? WHERE id=?")->execute([$cond,$itemId]);
                if ($qty<=0) continue;
                $office=(string)($ret['office_code'] ?: $ret['do_office']);
                if ($cond==='GOOD') {
                    sdret_add_stock($pdo,$pid,$qty,$office);
                } else {
                    $q=$pdo->prepare("INSERT INTO wqs_stock_quarantine(return_id,do_id,product_id,sku,qty,reason,office_code,status) VALUES(?,?,?,?,?,?,?,'WAITING_QC')");
                    $q->execute([$return_id,(int)$ret['do_id'],$pid,(string)($it['sku'] ?? ''),$qty,$cond,sdret_canonical_office($pdo,$office)]);
                }
                $pdo->prepare("UPDATE sales_do_return_items SET stock_returned=1,stock_returned_at=NOW() WHERE id=?")->execute([$itemId]);
            }
            $pdo->prepare("UPDATE sales_do_returns SET status='return_stocked',received_by=?,received_at=NOW(),updated_at=NOW() WHERE id=?")->execute([sdret_actor(),$return_id]);
            $pdo->prepare("UPDATE sales_do SET return_status='return_stocked',return_last_at=NOW() WHERE id=?")->execute([(int)$ret['do_id']]);
            sdret_tx_commit($pdo);
            sdret_save_photos($pdo,$return_id,(int)$ret['do_id'],'RECEIVED_WAREHOUSE',sdret_uploads('receive_photos','uploads/sales_returns'));
            $success='Retur diterima. Barang GOOD telah masuk stok; barang non-GOOD masuk antrean karantina WQS.';
            $do_id=(int)$ret['do_id'];
        }

        if ($action === 'correct_condition') {
            if (!$scope['isScm']) throw new Exception('Hanya SCM/Admin atau user cabang terkait yang dapat mengoreksi kondisi retur.');
            $st=$pdo->prepare("SELECT r.*,d.office_code AS do_office FROM sales_do_returns r JOIN sales_do d ON d.id=r.do_id WHERE r.id=? LIMIT 1");
            $st->execute([$return_id]); $ret=$st->fetch(PDO::FETCH_ASSOC);
            if (!$ret) throw new Exception('Retur tidak ditemukan.');
            sdret_do_scope_guard($pdo,['office_code'=>$ret['office_code'] ?: $ret['do_office']]);
            if (strtolower((string)($ret['status'] ?? '')) !== 'return_stocked') {
                throw new Exception('Koreksi kondisi hanya tersedia setelah stok/karantina diproses dan sebelum proses SCM diselesaikan.');
            }
            $finalConds=(array)($_POST['final_condition'] ?? []);
            $office=(string)($ret['office_code'] ?: $ret['do_office']);

            sdret_tx_begin($pdo);
            $itemsSt=$pdo->prepare("SELECT * FROM sales_do_return_items WHERE return_id=? FOR UPDATE");
            $itemsSt->execute([$return_id]); $items=$itemsSt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as $it) {
                $itemId=(int)$it['id'];
                $oldCond=strtoupper(trim((string)($it['condition_status'] ?? 'UNKNOWN')));
                $newCond=strtoupper(trim((string)($finalConds[$itemId] ?? $oldCond)));
                if (!in_array($newCond,['GOOD','DAMAGED','EXPIRED','UNKNOWN'],true)) $newCond='UNKNOWN';
                if ($newCond === $oldCond) continue;

                $qty=(float)($it['qty_return'] ?? 0);
                $pid=(int)($it['product_id'] ?? 0);
                if ($qty <= 0) continue;

                // Batalkan proses lama terlebih dahulu.
                if ($oldCond === 'GOOD') {
                    sdret_remove_stock($pdo,$pid,$qty,$office);
                } else {
                    $qc=$pdo->prepare("SELECT COUNT(*) FROM wqs_stock_quarantine WHERE return_id=? AND product_id=? AND UPPER(COALESCE(status,''))='QC_COMPLETED'");
                    $qc->execute([$return_id,$pid]);
                    if ((int)$qc->fetchColumn() > 0) throw new Exception('Kondisi tidak dapat dikoreksi karena QC WQS sudah selesai untuk SKU '.(string)($it['sku'] ?? ''));
                    $pdo->prepare("DELETE FROM wqs_stock_quarantine WHERE return_id=? AND product_id=? AND qty=?")
                        ->execute([$return_id,$pid,$qty]);
                }

                // Terapkan proses baru.
                if ($newCond === 'GOOD') {
                    sdret_add_stock($pdo,$pid,$qty,$office);
                } else {
                    $q=$pdo->prepare("INSERT INTO wqs_stock_quarantine(return_id,do_id,product_id,sku,qty,reason,office_code,status) VALUES(?,?,?,?,?,?,?,'WAITING_QC')");
                    $q->execute([$return_id,(int)$ret['do_id'],$pid,(string)($it['sku'] ?? ''),$qty,$newCond,sdret_canonical_office($pdo,$office)]);
                }
                $pdo->prepare("UPDATE sales_do_return_items SET condition_status=?,stock_returned=1,stock_returned_at=NOW() WHERE id=?")
                    ->execute([$newCond,$itemId]);
            }
            $pdo->prepare("UPDATE sales_do_returns SET updated_at=NOW() WHERE id=?")->execute([$return_id]);
            sdret_tx_commit($pdo);
            $success='Kondisi final SCM berhasil dikoreksi. Stok dan antrean karantina telah disesuaikan otomatis.';
            $do_id=(int)$ret['do_id'];
        }

        if ($action === 'complete_scm') {
            if (!$scope['isScm']) throw new Exception('Hanya SCM/Admin atau user cabang terkait yang dapat menyelesaikan proses retur SCM.');
            $st=$pdo->prepare("SELECT r.*,d.office_code AS do_office FROM sales_do_returns r JOIN sales_do d ON d.id=r.do_id WHERE r.id=? LIMIT 1");
            $st->execute([$return_id]); $ret=$st->fetch(PDO::FETCH_ASSOC);
            if (!$ret) throw new Exception('Retur tidak ditemukan.');
            sdret_do_scope_guard($pdo,['office_code'=>$ret['office_code'] ?: $ret['do_office']]);
            $retStatus=strtolower((string)($ret['status'] ?? ''));
            if ($retStatus === 'return_scm_completed') throw new Exception('Proses SCM untuk retur ini sudah selesai.');
            if ($retStatus !== 'return_stocked') throw new Exception('SCM hanya dapat menyelesaikan retur setelah barang diterima dan stok/karantina sudah diproses.');
            $scmNote=trim((string)($_POST['scm_completion_note'] ?? ''));
            if ($scmNote==='') throw new Exception('Catatan penyelesaian SCM wajib diisi.');

            // Physical return and commercial/revenue effect are different dimensions.
            $commercialEffect=strtoupper(trim((string)($_POST['commercial_effect'] ?? '')));
            $commercialEffectReason=trim((string)($_POST['commercial_effect_reason'] ?? ''));
            if(!in_array($commercialEffect,['REVERSAL','OPERATIONAL_ONLY','REPLACEMENT'],true)){
                throw new Exception('Pengaruh komersial retur wajib dipilih: REVERSAL, OPERATIONAL_ONLY, atau REPLACEMENT.');
            }
            if(mb_strlen($commercialEffectReason)<8){
                throw new Exception('Alasan pengaruh komersial wajib diisi minimal 8 karakter.');
            }

            $commercialSummary=sdret_return_value_summary($pdo,$return_id);
            sdret_commercial_effect_guard($scope,$commercialEffect,$commercialEffectReason,$commercialSummary,$_POST);

            // Integrity guard retur final: hanya item milik DO asal dan qty > 0 yang boleh difinalkan.
            // Retur di modul ini adalah pergerakan barang/stok; bukan credit note revenue.
            $chkItems=$pdo->prepare("SELECT i.id,i.do_item_id,i.qty_return,di.do_id AS original_item_do_id
                                     FROM sales_do_return_items i
                                     LEFT JOIN sales_do_items di ON di.id=i.do_item_id
                                     WHERE i.return_id=?");
            $chkItems->execute([$return_id]);
            $retItemsCheck=$chkItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$retItemsCheck) throw new Exception('Retur tidak memiliki item yang valid.');
            $seenReturnItems=[];
            foreach ($retItemsCheck as $ci) {
                $diid=(int)($ci['do_item_id'] ?? 0);
                if ($diid<=0 || (int)($ci['original_item_do_id'] ?? 0)!==(int)$ret['do_id']) {
                    throw new Exception('Link item retur tidak sesuai dengan DO asal. Proses SCM dibatalkan.');
                }
                if ((float)($ci['qty_return'] ?? 0)<=0) {
                    throw new Exception('Qty retur final harus lebih dari 0.');
                }
                if (isset($seenReturnItems[$diid])) {
                    throw new Exception('Item DO yang sama tercatat ganda pada satu retur. Perbaiki data sebelum finalisasi.');
                }
                $seenReturnItems[$diid]=true;
            }

            sdret_tx_begin($pdo);
            $pdo->prepare("UPDATE sales_do_returns
                           SET status='return_scm_completed',
                               scm_note=?,
                               scm_completed_by=?,
                               scm_completed_at=NOW(),
                               commercial_effect=?,
                               commercial_effect_set_by=?,
                               commercial_effect_set_at=NOW(),
                               commercial_effect_reason=?,
                               updated_at=NOW()
                           WHERE id=?")
                ->execute([
                    $scmNote . sprintf(
                        "\n[COMMERCIAL GUARD] effect=%s; qty_return=%s; return_net=%s",
                        $commercialEffect,
                        rtrim(rtrim(number_format((float)$commercialSummary['qty_return'],2,'.',''),'0'),'.'),
                        number_format((float)$commercialSummary['return_net'],2,'.','')
                    ),
                    sdret_actor(),$commercialEffect,sdret_actor(),$commercialEffectReason,$return_id
                ]);
            $pdo->prepare("UPDATE sales_do SET return_status='return_scm_completed',return_last_at=NOW() WHERE id=?")
                ->execute([(int)$ret['do_id']]);

            // FINAL RETURN GUARD: evaluasi qty setelah retur ini resmi selesai SCM.
            // Main sales_do.status sengaja TIDAK diubah agar flow CRM->WQS->SCM->ACT->FIN lama tetap aman.
            $retProgress = sdret_return_progress($pdo, (int)$ret['do_id']);
            if (!empty($retProgress['is_full'])) {
                // Tandai return terakhir sebagai FULL agar mudah dibaca audit/UI.
                $pdo->prepare("UPDATE sales_do_returns SET return_type='FULL',updated_at=NOW() WHERE id=?")
                    ->execute([$return_id]);

                // Hentikan hanya session GPS aktif. History titik GPS tidak dihapus.
                try {
                    $pdo->prepare("UPDATE sales_shipment_tracking_sessions
                                   SET status='STOPPED', stopped_at=COALESCE(stopped_at,NOW()),
                                       stop_reason='FULL_RETURN', updated_at=NOW()
                                   WHERE do_id=? AND status='ACTIVE'")
                        ->execute([(int)$ret['do_id']]);
                } catch (Throwable $e) {
                    // fail-soft: tabel tracking history mungkin belum tersedia di instalasi lama.
                }
            }

            sdret_tx_commit($pdo);
            if (!empty($retProgress['is_full'])) {
                $success='Proses retur FULL selesai di SCM. Seluruh qty sudah diretur; live tracking dihentikan, tetapi status workflow utama dan history tetap dipertahankan.';
            } else {
                $success='Proses retur PARTIAL selesai di SCM. Sisa qty masih dapat melanjutkan pengiriman; riwayat stok dan karantina tetap tersimpan.';
            }
            $do_id=(int)$ret['do_id'];
        }

        if ($action === 'qc_complete') {
            if (!$scope['isWqs']) throw new Exception('Hanya WQS/Admin atau user cabang terkait yang dapat menyelesaikan QC karantina.');
            $qid=(int)($_POST['quarantine_id'] ?? 0);
            $note=trim((string)($_POST['qc_note'] ?? ''));
            $qcResult=strtoupper(trim((string)($_POST['qc_result'] ?? 'UNKNOWN')));
            if (!in_array($qcResult,['GOOD','DAMAGED','EXPIRED','UNKNOWN'],true)) $qcResult='UNKNOWN';

            sdret_tx_begin($pdo);
            $st=$pdo->prepare("SELECT * FROM wqs_stock_quarantine WHERE id=? LIMIT 1 FOR UPDATE");
            $st->execute([$qid]); $qrow=$st->fetch(PDO::FETCH_ASSOC);
            if (!$qrow) throw new Exception('Data karantina tidak ditemukan.');
            sdret_do_scope_guard($pdo,['office_code'=>$qrow['office_code'] ?? '']);
            if (strtoupper((string)($qrow['status'] ?? '')) === 'QC_COMPLETED') throw new Exception('QC karantina ini sudah selesai. Stok tidak boleh diproses ulang.');

            $returnId=(int)($qrow['return_id'] ?? 0);
            $productId=(int)($qrow['product_id'] ?? 0);
            $qty=(float)($qrow['qty'] ?? 0);
            $office=(string)($qrow['office_code'] ?? '');
            $released=0;

            if ($qcResult === 'GOOD') {
                sdret_add_stock($pdo,$productId,$qty,$office);
                $released=1;
                $pdo->prepare("UPDATE sales_do_return_items
                    SET condition_status='GOOD',stock_returned=1,stock_returned_at=NOW()
                    WHERE return_id=? AND product_id=? AND qty_return=? LIMIT 1")
                    ->execute([$returnId,$productId,$qty]);
            }

            $pdo->prepare("UPDATE wqs_stock_quarantine
                SET status='QC_COMPLETED',qc_note=?,qc_result=?,qc_by=?,qc_at=NOW(),stock_released=?,stock_released_at=CASE WHEN ?=1 THEN NOW() ELSE stock_released_at END
                WHERE id=?")
                ->execute([$note,$qcResult,sdret_actor(),$released,$released,$qid]);
            sdret_tx_commit($pdo);
            $success=($qcResult==='GOOD')
                ? 'QC karantina selesai. Barang GOOD otomatis dikembalikan ke stok office.'
                : 'QC karantina selesai. Barang tidak dikembalikan ke stok karena hasil QC bukan GOOD.';
        }
    }
} catch (Throwable $e) {
    sdret_tx_rollback($pdo);
    $error=$e->getMessage();
}

$do=null; $items=[]; $returns=[]; $queueRows=[]; $quarantineRows=[];
if ($do_id>0) {
    try {
        $st=$pdo->prepare("SELECT d.*,c.customers_name FROM sales_do d LEFT JOIN master_customers c ON c.customers_code=d.customers_code WHERE d.id=? LIMIT 1");
        $st->execute([$do_id]); $do=$st->fetch(PDO::FETCH_ASSOC);
        if ($do) sdret_do_scope_guard($pdo,$do);
        if ($do) {
            $stI=$pdo->prepare("SELECT * FROM sales_do_items WHERE do_id=? ORDER BY line_no ASC,id ASC"); $stI->execute([$do_id]); $items=$stI->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$it) {
                $it['_already_returned']=sdret_already_returned_qty($pdo,(int)$it['id']);
                [$it['_business_group'],$it['_category']]=sdret_item_classification($pdo,$it);
            }
            unset($it);
            $stR=$pdo->prepare("SELECT * FROM sales_do_returns WHERE do_id=? ORDER BY id DESC"); $stR->execute([$do_id]); $returns=$stR->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) { $error=$error ?: $e->getMessage(); }
}

try {
    if ($do_id<=0 || $queue!=='') {
        $sql="SELECT r.*,d.status AS do_status,c.customers_name FROM sales_do_returns r JOIN sales_do d ON d.id=r.do_id LEFT JOIN master_customers c ON c.customers_code=d.customers_code WHERE 1=1";
        $params=[];
        if ($scope['branch']) {
            if (function_exists('rmi_office_in_sql')) $sql.=' AND '.rmi_office_in_sql($pdo,'r.office_code',$scope['office'],$params);
            else { $sql.=' AND UPPER(TRIM(r.office_code))=?'; $params[]=$scope['office']; }
        }
        if ($queue==='scm') $sql.=" AND r.status IN ('return_requested','return_stocked','return_scm_completed')";
        $sql.=' ORDER BY r.id DESC LIMIT 500';
        $st=$pdo->prepare($sql); $st->execute($params); $queueRows=$st->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($queue==='wqs') {
        $sql="SELECT q.*,r.return_code,r.status AS return_status,d.do_code,c.customers_name FROM wqs_stock_quarantine q JOIN sales_do_returns r ON r.id=q.return_id JOIN sales_do d ON d.id=q.do_id LEFT JOIN master_customers c ON c.customers_code=d.customers_code WHERE 1=1";
        $params=[];
        if ($scope['branch']) {
            if (function_exists('rmi_office_in_sql')) $sql.=' AND '.rmi_office_in_sql($pdo,'q.office_code',$scope['office'],$params);
            else { $sql.=' AND UPPER(TRIM(q.office_code))=?'; $params[]=$scope['office']; }
        }
        $sql.=' ORDER BY FIELD(q.status,\'WAITING_QC\',\'QC_COMPLETED\'),q.id DESC';
        $st=$pdo->prepare($sql); $st->execute($params); $quarantineRows=$st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { $error=$error ?: $e->getMessage(); }

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';
$actions=[];
if ($scope['isCrm']) $actions[]=['label'=>'CRM Sales DO','url'=>$baseProject.'/sales/sales_do.php','class'=>'btn btn-sm btn-outline-light'];
if ($scope['isScm']) $actions[]=['label'=>'Antrean SCM','url'=>$baseProject.'/sales/sales_do_return.php?queue=scm','class'=>'btn btn-sm btn-outline-warning'];
if ($scope['isWqs']) $actions[]=['label'=>'Karantina WQS','url'=>$baseProject.'/sales/sales_do_return.php?queue=wqs','class'=>'btn btn-sm btn-outline-warning'];
rmi_header('Retur Sales DO',[
    'active'=>'sales','subtitle'=>'CRM membuat request; SCM menerima dan memproses; setelah FINAL, barang pengganti wajib dimulai dari riwayat retur agar tidak menjadi Sales DO baru.',
    'breadcrumbs'=>[['label'=>'Sales DO','url'=>$baseProject.'/sales/sales_do.php'],'Retur DO'],'actions'=>$actions,
    'extra_head'=>'<style>.rmi-box{background:rgba(15,23,42,.72);border:1px solid rgba(148,163,184,.22);border-radius:16px;padding:16px;margin:14px 0}.table-retur{width:100%;border-collapse:collapse}.table-retur th,.table-retur td{border-bottom:1px solid rgba(148,163,184,.18);padding:8px;vertical-align:top}.field{width:100%;background:#111827;color:#e5e7eb;border:1px solid #475569;border-radius:8px;padding:8px}.btn-rmi{display:inline-block;border:1px solid #475569;border-radius:10px;padding:8px 12px;color:#e5e7eb;text-decoration:none;background:#1f2937}.btn-blue{background:#2563eb}.btn-green{background:#16a34a}.muted{color:#94a3b8;font-size:12px}.badge{border-radius:999px;padding:4px 8px;background:#334155}.warn{color:#fde68a}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:900px){.grid2{grid-template-columns:1fr}}</style>'
]);
?>
<div class="container-fluid">
<?php if(!empty($scope['admin']) && !empty($finalReturnAudit)): ?>
<details style="border:1px solid rgba(96,165,250,.45);border-radius:12px;padding:12px;margin:12px 0;background:rgba(30,64,175,.12)" open>
  <summary style="cursor:pointer;font-weight:800">Audit Retur FINAL — Pengurang Executive Summary</summary>
  <div class="muted" style="margin:8px 0">
    Source yang dibaca Executive Summary adalah retur <code>return_scm_completed</code>.
    Nilai = qty_return × net unit dari DO asal. Jika ada retur FINAL yang memang salah operator,
    batalkan dari baris tersebut; jangan menambal nominal di dashboard.
  </div>
  <div style="overflow:auto">
    <table>
      <thead>
        <tr><th>Return ID</th><th>Return</th><th>DO</th><th>Type</th><th>Selesai SCM</th><th>Items</th><th>Return Net</th><th>Commercial Effect</th><th>Replacement</th><th>Aksi SYS</th></tr>
      </thead>
      <tbody>
      <?php foreach($finalReturnAudit as $fa): ?>
        <tr>
          <td><?= (int)$fa['return_id'] ?></td>
          <td><b><?= h($fa['return_code']??'') ?></b></td>
          <td><a href="sales_do_view.php?id=<?= (int)$fa['do_id'] ?>" target="_blank"><?= h($fa['do_code']??'') ?></a></td>
          <td><?= h($fa['return_type']??'') ?></td>
          <td><?= h($fa['scm_completed_at']??'') ?></td>
          <td><?= (int)($fa['item_count']??0) ?></td>
          <td><b>Rp <?= number_format((float)($fa['return_net']??0),0,',','.') ?></b></td>
          <td>
            <?php $faCe=strtoupper(trim((string)($fa['commercial_effect']??''))); ?>
            <b><?= h($faCe!==''?$faCe:'LEGACY / NEEDS_REVIEW') ?></b>
            <?php if($scope['admin']): ?>
              <details style="margin-top:5px">
                <summary style="cursor:pointer;color:#67e8f9">Klasifikasi Komersial</summary>
                <form method="post" style="margin-top:6px"
                      onsubmit="return confirm('Simpan pengaruh komersial retur FINAL ini? Stok dan histori retur tidak diubah.')">
                  <input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token')?csrf_token():'') ?>">
                  <input type="hidden" name="action" value="set_return_commercial_effect">
                  <input type="hidden" name="return_id" value="<?= (int)$fa['return_id'] ?>">
                  <label style="display:block"><input type="radio" name="commercial_effect" value="REVERSAL" <?= $faCe==='REVERSAL'?'checked':'' ?> required> REVERSAL — retur mengurangi penjualan</label>
                  <label style="display:block"><input type="radio" name="commercial_effect" value="OPERATIONAL_ONLY" <?= $faCe==='OPERATIONAL_ONLY'?'checked':'' ?> required> OPERATIONAL_ONLY — stok/operasional saja</label>
                  <label style="display:block"><input type="radio" name="commercial_effect" value="REPLACEMENT" <?= $faCe==='REPLACEMENT'?'checked':'' ?> required> REPLACEMENT — retur + barang pengganti</label>
                  <input class="field" name="commercial_effect_reason" minlength="8" required
                         placeholder="Alasan klasifikasi, min. 8 karakter" style="margin-top:5px">
                  <label style="display:block;margin-top:6px"><input type="checkbox" name="billing_unchanged_confirm" value="1"> Billing/PO tetap sama (wajib bila OPERATIONAL_ONLY)</label>
                  <label style="display:block;margin-top:6px"><input type="checkbox" name="operational_override_confirm" value="1"> SYS override untuk catatan yang mengindikasikan revisi QTY/PO</label>
                  <label style="display:block;margin-top:6px"><input type="checkbox" name="replacement_commit_confirm" value="1"> DO pengganti akan dibuat dari retur (wajib bila REPLACEMENT)</label>
                  <label style="display:block;margin-top:6px"><input type="checkbox" name="commercial_effect_confirm" value="1" required> Saya sudah verifikasi dampak komersial.</label>
                  <button class="btn-rmi" type="submit" style="margin-top:5px">Simpan Klasifikasi</button>
                </form>
              </details>
            <?php endif; ?>
          </td>
          <td><?= (int)($fa['replacement_do_id']??0)>0 ? ('DO ID '.(int)$fa['replacement_do_id']) : '-' ?></td>
          <td>
            <?php if((int)($fa['replacement_do_id']??0)===0): ?>
            <details>
              <summary style="cursor:pointer;color:#fbbf24">Batalkan jika salah operator</summary>
              <form method="post" style="margin-top:6px"
                    onsubmit="return confirm('FINAL: retur ini memang salah operator dan tidak boleh mengurangi achievement. Sistem akan membalik stok dan mengubah status retur menjadi cancelled. Lanjutkan?')">
                <input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token')?csrf_token():'') ?>">
                <input type="hidden" name="action" value="cancel_final_return_operator_error">
                <input type="hidden" name="return_id" value="<?= (int)$fa['return_id'] ?>">
                <input class="field" name="correction_reason" minlength="8" required placeholder="Alasan koreksi retur salah">
                <label style="display:block;margin-top:5px">
                  <input type="checkbox" name="correction_confirm" value="1" required>
                  Saya konfirmasi retur FINAL ini salah operator.
                </label>
                <button class="btn-rmi" type="submit" style="margin-top:5px">Batalkan Retur FINAL Salah</button>
              </form>
            </details>
            <?php else: ?>
              <span class="muted">Ada replacement link</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if ($queue==='wqs'): ?>
<div class="rmi-box"><h4>Antrean Karantina Retur WQS</h4><div class="muted">Barang DAMAGED, EXPIRED, atau UNKNOWN. Jika hasil QC menjadi GOOD, stok otomatis kembali ke office asal; selain GOOD tetap tidak masuk stok.</div></div>
<div class="rmi-box">
<table class="table-retur"><thead><tr><th>Retur / DO</th><th>Customer / Office</th><th>Barang</th><th>Qty / Kondisi</th><th>Status QC</th><th>Aksi</th></tr></thead><tbody>
<?php if (!$quarantineRows): ?><tr><td colspan="6" class="muted">Tidak ada barang retur dalam karantina.</td></tr><?php endif; ?>
<?php foreach ($quarantineRows as $q): ?>
<tr><td><b><?= h($q['return_code']) ?></b><br><span class="muted"><?= h($q['do_code']) ?></span></td><td><?= h($q['customers_name'] ?? '-') ?><br><span class="muted"><?= h($q['office_code']) ?></span></td><td><?= h($q['sku']) ?></td><td><?= h($q['qty']) ?><br><b><?= h($q['reason']) ?></b></td><td><?= h($q['status']) ?><br><span class="muted"><?= h($q['qc_note'] ?? '') ?></span></td><td>
<?php if (($q['status'] ?? '')==='WAITING_QC' && $scope['isWqs']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token')?csrf_token():'') ?>"><input type="hidden" name="action" value="qc_complete"><input type="hidden" name="quarantine_id" value="<?= (int)$q['id'] ?>"><select class="field" name="qc_result" required><option value="GOOD">GOOD — kembali ke stok office</option><option value="DAMAGED">DAMAGED — tidak masuk stok</option><option value="EXPIRED">EXPIRED — tidak masuk stok</option><option value="UNKNOWN">UNKNOWN — tidak masuk stok</option></select><input class="field" name="qc_note" placeholder="Catatan QC / tindak lanjut" required style="margin-top:6px"><button class="btn-rmi btn-green" style="margin-top:6px">Selesaikan QC</button></form><?php elseif (($q['status'] ?? '')==='QC_COMPLETED'): ?><span class="muted">Hasil QC: <b><?= h($q['qc_result'] ?? '-') ?></b><?= !empty($q['stock_released']) ? ' · Stok dikembalikan' : '' ?></span><?php endif; ?>
</td></tr><?php endforeach; ?>
</tbody></table></div>
<?php elseif ($do_id<=0 || $queue==='scm'): ?>
<div class="rmi-box"><h4>Antrean Retur SCM</h4><div class="muted">CRM membuat pengajuan. SCM menerima barang, menentukan kondisi final, memproses stok/karantina, lalu menutup proses operasional SCM.</div></div>
<div class="rmi-box"><table class="table-retur"><thead><tr><th>Kode Retur</th><th>DO / Customer</th><th>Office</th><th>Tipe</th><th>Status</th><th>Pengaju</th><th>Aksi</th></tr></thead><tbody>
<?php if (!$queueRows): ?><tr><td colspan="7" class="muted">Belum ada antrean retur.</td></tr><?php endif; ?>
<?php foreach ($queueRows as $r): ?><tr><td><b><?= h($r['return_code']) ?></b><br><span class="muted"><?= h($r['created_at']) ?></span></td><td><?= h($r['do_code']) ?><br><span class="muted"><?= h($r['customers_name'] ?? $r['customer_code']) ?></span></td><td><?= h($r['office_code']) ?></td><td><?= h($r['return_type']) ?></td><td><span class="badge"><?= h(sdret_status_label((string)$r['status'])) ?></span></td><td><?= h($r['requested_by']) ?></td><td><a class="btn-rmi btn-blue" href="sales_do_return.php?do_id=<?= (int)$r['do_id'] ?>">Buka</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php else: ?>
<div class="rmi-box"><form method="get" style="display:flex;gap:8px;align-items:end;max-width:520px"><div style="flex:1"><label>ID DO</label><input class="field" name="do_id" value="<?= (int)$do_id ?>"></div><button class="btn-rmi btn-blue">Buka DO</button></form></div>
<?php if ($do): ?>
<?php
$doBg = strtoupper(trim((string)($do['business_group'] ?? '')));
if (!in_array($doBg,['BMHP','UNIT_ACC'],true)) {
    $doBg = strtoupper(trim((string)($do['category'] ?? ''))) === 'UNIT_ACC' ? 'UNIT_ACC' : 'BMHP';
}
// Legacy guard: header lama dapat masih default BMHP; snapshot item transaksi menjadi bukti tambahan.
foreach ($items as $ix) if (($ix['_business_group'] ?? '') === 'UNIT_ACC') { $doBg='UNIT_ACC'; break; }
?>
<div class="rmi-box"><h4>DO <?= h($do['do_code'] ?? '') ?></h4><div class="muted">Customer: <b><?= h($do['customers_name'] ?? $do['customers_code'] ?? '') ?></b> · Office: <b><?= h($do['office_code'] ?? '') ?></b> · Kelompok: <b><?= h($doBg==='UNIT_ACC'?'UNIT ACC':'BMHP') ?></b> · Status: <b><?= h($do['status'] ?? '') ?></b></div></div>

<?php if ($scope['isCrm'] && in_array(strtolower((string)($do['status'] ?? '')),['on_delivery','delivered','wait_payment','paid'],true)): ?>
<div class="rmi-box"><h5>Buat Pengajuan Retur — CRM</h5><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token')?csrf_token():'') ?>"><input type="hidden" name="do_id" value="<?= (int)$do_id ?>"><input type="hidden" name="action" value="create_return"><label>Alasan Retur *</label><textarea class="field" name="reason" required></textarea><div class="muted" style="margin:8px 0">CRM hanya mengajukan qty. Kondisi akhir diverifikasi SCM saat barang diterima.</div><table class="table-retur"><thead><tr><th>Produk</th><th>Qty DO</th><th>Sudah Retur</th><th>Sisa</th><th>Qty Retur</th></tr></thead><tbody>
<?php foreach ($items as $it): $iid=(int)$it['id']; $qtyDo=(float)($it['qty']??0); $already=(float)($it['_already_returned']??0); $remain=max(0,$qtyDo-$already); ?>
<tr><td><b><?= h($it['products_name'] ?? '') ?></b><br><span class="muted"><?= h($it['sku'] ?? '') ?> · <?= h(($it['_business_group'] ?? '')==='UNIT_ACC' ? ('UNIT ACC / '.($it['_category'] ?? '')) : ($it['_category'] ?? 'BMHP')) ?> · <?= h($it['serial_lot'] ?? '-') ?></span></td><td><?= h($qtyDo) ?> <?= h($it['unit'] ?? '') ?></td><td><?= h($already) ?></td><td><?= h($remain) ?></td><td><input class="field" type="number" step="0.01" min="0" max="<?= h($remain) ?>" name="qty_return[<?= $iid ?>]" value="0" <?= $remain<=0?'disabled':'' ?>></td></tr><?php endforeach; ?>
</tbody></table><label>Foto bukti retur (opsional)</label><input class="field" type="file" name="return_photos[]" accept="image/*,.pdf" multiple><button class="btn-rmi btn-blue" style="margin-top:12px">Buat Retur</button></form></div>
<?php elseif (strtolower((string)($do['status'] ?? ''))==='ready_scm'): ?><div class="alert alert-warning">Barang belum dikirim. Gunakan menu <b>Revisi / Kembalikan ke WQS</b>, bukan retur.</div><?php endif; ?>

<div class="rmi-box"><h5>Riwayat Retur</h5><?php if (!$returns): ?><div class="muted">Belum ada retur.</div><?php endif; ?>
<?php foreach ($returns as $rt): ?><div style="border:1px solid rgba(148,163,184,.2);border-radius:12px;padding:12px;margin:10px 0"><b><?= h($rt['return_code']) ?></b> <span class="badge"><?= h(sdret_status_label((string)$rt['status'])) ?></span> <span class="muted">· <?= h($rt['return_type']) ?> · <?= h($rt['created_at']) ?></span><div class="muted">Alasan: <?= h($rt['reason'] ?? '') ?></div>
<?php
$stIt=$pdo->prepare("SELECT * FROM sales_do_return_items WHERE return_id=? ORDER BY id ASC");
$stIt->execute([(int)$rt['id']]);
$retItems=$stIt->fetchAll(PDO::FETCH_ASSOC);
foreach($retItems as &$riClass){ [$riClass['_business_group'],$riClass['_category']]=sdret_return_item_classification($pdo,$riClass); }
unset($riClass);
$rtStatus = strtolower(trim((string)($rt['status'] ?? '')));
$rtWorkflowForm = $scope['isScm'] && in_array($rtStatus,['return_requested','return_stocked'],true);
$rtWorkflowAction = $rtStatus==='return_stocked' ? 'correct_condition' : 'receive_return';
$rtConfirm = $rtStatus==='return_stocked' ? 'Koreksi kondisi final dan sesuaikan stok/karantina?' : 'Terima retur dan proses stok sesuai kondisi final?';
?>
<?php if($rtWorkflowForm): ?><form method="post" enctype="multipart/form-data" onsubmit="return confirm('<?= h($rtConfirm) ?>')"><input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token')?csrf_token():'') ?>"><input type="hidden" name="return_id" value="<?= (int)$rt['id'] ?>"><input type="hidden" name="action" value="<?= h($rtWorkflowAction) ?>"><?php endif; ?><table class="table-retur"><thead><tr><th>Barang</th><th>Qty</th><th>Kondisi Final SCM</th><th>Proses</th></tr></thead><tbody>
<?php foreach ($retItems as $ri): ?>
<tr>
  <td>
    <?= h($ri['product_name']) ?><br>
    <span class="muted"><?= h($ri['sku']) ?> · <?= h(($ri['_business_group'] ?? '')==='UNIT_ACC' ? ('UNIT ACC / '.($ri['_category'] ?? '')) : ($ri['_category'] ?? 'BMHP')) ?> · Product ID <?= (int)($ri['product_id']??0) ?> · Return Item ID <?= (int)$ri['id'] ?></span>
  </td>
  <td><?= h($ri['qty_return']) ?> <?= h($ri['unit']) ?></td>
  <td>
    <?php if (!in_array(($rt['status']??''),['return_scm_completed'],true) && $scope['isScm']): ?>
      <select class="field" name="final_condition[<?= (int)$ri['id'] ?>">
        <option value="GOOD" <?= ($ri['condition_status']??'')==='GOOD'?'selected':'' ?>>GOOD — masuk stok</option>
        <option value="DAMAGED" <?= ($ri['condition_status']??'')==='DAMAGED'?'selected':'' ?>>DAMAGED — karantina WQS</option>
        <option value="EXPIRED" <?= ($ri['condition_status']??'')==='EXPIRED'?'selected':'' ?>>EXPIRED — karantina WQS</option>
        <option value="UNKNOWN" <?= ($ri['condition_status']??'')==='UNKNOWN'?'selected':'' ?>>UNKNOWN — karantina WQS</option>
      </select>
    <?php else: ?>
      <?= h($ri['condition_status']) ?>
    <?php endif; ?>
  </td>
  <td>
    <?= (int)$ri['stock_returned']===1?'Sudah diproses':'Belum diproses' ?>
    <?php if (($rt['status']??'')==='return_scm_completed' && !empty($scope['admin'])): ?>
      <details style="margin-top:8px">
        <summary style="cursor:pointer;color:#fbbf24;font-weight:700">Koreksi item salah operator</summary>
        <form method="post"
              style="margin-top:8px"
              onsubmit="return confirm('FINAL: item ini sebenarnya TIDAK DIRETUR. Sistem akan menghapusnya dari ledger retur dan membalik dampak stok. Lanjutkan?')">
          <input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token')?csrf_token():'') ?>">
          <input type="hidden" name="action" value="correct_final_return_item">
          <input type="hidden" name="return_item_id" value="<?= (int)$ri['id'] ?>">
          <input class="field" name="correction_reason"
                 placeholder="Alasan, contoh: operator salah mencentang item ini saat membuat retur"
                 minlength="8" required style="margin-top:6px">
          <label style="display:block;margin-top:6px">
            <input type="checkbox" name="correction_confirm" value="1" required>
            Saya konfirmasi item ini tidak benar-benar diretur.
          </label>
          <button class="btn-rmi" type="submit" style="margin-top:6px">Hapus Item Salah & Balik Stok</button>
        </form>
      </details>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table><?php if ($rtWorkflowForm && $rtStatus==='return_requested'): ?><label>Foto barang diterima gudang (opsional)</label><input class="field" type="file" name="receive_photos[]" accept="image/*,.pdf" multiple><button class="btn-rmi btn-green" style="margin-top:8px">1. Retur Diterima / Proses Stok</button><?php elseif ($rtWorkflowForm && $rtStatus==='return_stocked'): ?><button class="btn-rmi" style="margin-top:8px" type="submit">Koreksi Kondisi Final SCM</button><div class="muted" style="margin-top:6px">Gunakan hanya bila kondisi sebelumnya salah. Sistem akan membalik proses lama lalu menerapkan kondisi baru.</div><?php endif; ?><?php if($rtWorkflowForm): ?></form><?php endif; ?>
<?php if (($rt['status']??'')==='return_stocked' && $scope['isScm']): ?>
<form method="post" onsubmit="return confirm('Tandai proses retur SCM selesai?')" style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(148,163,184,.18)">
<input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token')?csrf_token():'') ?>"><input type="hidden" name="return_id" value="<?= (int)$rt['id'] ?>"><input type="hidden" name="action" value="complete_scm">
<label>Catatan Penyelesaian SCM *</label><textarea class="field" name="scm_completion_note" required placeholder="Contoh: barang diterima lengkap, kondisi telah diverifikasi, stok/karantina sudah diproses"></textarea>
<?php $rtCommercialSummary=sdret_return_value_summary($pdo,(int)$rt['id']); ?>
<div style="margin-top:10px;padding:10px;border:1px solid rgba(148,163,184,.25);border-radius:10px">
  <b>Pengaruh Komersial / Pencapaian Penjualan *</b>
  <div class="muted" style="margin:6px 0 10px">
    Nilai item retur: <b>Rp <?= h(number_format((float)$rtCommercialSummary['return_net'],0,',','.')) ?></b>
    · Qty retur: <b><?= h(rtrim(rtrim(number_format((float)$rtCommercialSummary['qty_return'],2,'.',''),'0'),'.')) ?></b>.
    Pilih berdasarkan dampak ke PO/invoice customer, bukan berdasarkan pergerakan stok.
  </div>
  <label style="display:block;margin-top:6px"><input type="radio" name="commercial_effect" value="REVERSAL" checked required> <b>Kurangi Penjualan (disarankan untuk retur karena QTY/PO customer berkurang)</b> — nilai item retur mengurangi pencapaian.</label>
  <label style="display:block;margin-top:6px"><input type="radio" name="commercial_effect" value="OPERATIONAL_ONLY" required> <b>Operasional Saja</b> — hanya bila PO/invoice/tagihan customer TETAP sama dan retur tidak mengurangi nilai penjualan.</label>
  <label style="display:block;margin-top:6px"><input type="checkbox" name="billing_unchanged_confirm" value="1"> Saya sudah memastikan PO/invoice/tagihan customer tetap sama (wajib jika memilih Operasional Saja).</label>
  <?php if(!empty($scope['admin'])): ?>
  <label style="display:block;margin-top:6px"><input type="checkbox" name="operational_override_confirm" value="1"> SYS override: catatan tampak seperti revisi/pengurangan QTY/PO tetapi billing memang tetap sama.</label>
  <?php endif; ?>
  <label style="display:block;margin-top:6px"><input type="radio" name="commercial_effect" value="REPLACEMENT" required> <b>Penggantian Barang</b> — DO pengganti wajib dibuat dari retur ini dan bukan order/penjualan baru.</label>
  <label style="display:block;margin-top:6px"><input type="checkbox" name="replacement_commit_confirm" value="1"> Saya konfirmasi DO pengganti akan dibuat dari tombol khusus retur (wajib jika memilih Penggantian Barang).</label>
  <label style="display:block;margin-top:8px">Alasan Pengaruh Komersial</label>
  <input class="field" name="commercial_effect_reason" minlength="8" required
         placeholder="Contoh: revisi QTY PO customer / billing tetap / barang diganti">
  <label style="display:block;margin-top:8px;font-weight:700"><input type="checkbox" name="commercial_effect_confirm" value="1" required> Saya sudah mencocokkan efek retur dengan PO/invoice customer dan memahami dampaknya ke pencapaian penjualan.</label>
  <div class="muted" style="margin-top:6px">Guard ERP: catatan seperti <b>revisi/pengurangan QTY/PO/tagihan</b> tidak dapat disimpan sebagai Operasional Saja kecuali SYS melakukan override eksplisit.</div>
</div>
<button class="btn-rmi btn-blue" style="margin-top:8px">2. Selesaikan Proses SCM</button>
<div class="muted" style="margin-top:6px">Setelah langkah ini, proses operasional SCM selesai. Barang non-GOOD tetap dilanjutkan pada antrean karantina WQS.</div>
</form>
<?php elseif (($rt['status']??'')==='return_scm_completed'): ?>
<?php $repDo = sdret_replacement_do($pdo, (int)$rt['id']); ?>
<div class="alert alert-success" style="margin-top:12px"><b>Proses SCM selesai.</b><br><?= h($rt['scm_note'] ?? '') ?><div class="muted"><?= h($rt['scm_completed_by'] ?? '') ?> · <?= h($rt['scm_completed_at'] ?? '') ?></div>
<div style="margin-top:8px"><b>Pengaruh Komersial:</b>
<?php $ce=strtoupper(trim((string)($rt['commercial_effect']??''))); ?>
<span class="badge"><?= h($ce===''?'NEEDS_REVIEW / BELUM DIKLASIFIKASI':$ce) ?></span>
<?php if(!empty($scope['admin'])): ?>
<details style="margin-top:8px">
  <summary style="cursor:pointer;font-weight:700">Koreksi Pengaruh Komersial (SYS)</summary>
  <form method="post" style="margin-top:8px" onsubmit="return confirm('Ubah pengaruh retur terhadap Executive Summary? Proses stok tidak diubah.')">
    <input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token')?csrf_token():'') ?>">
    <input type="hidden" name="action" value="set_return_commercial_effect">
    <input type="hidden" name="return_id" value="<?= (int)$rt['id'] ?>">
    <label><input type="radio" name="commercial_effect" value="REVERSAL" <?= $ce==='REVERSAL'?'checked':'' ?> required> Kurangi Penjualan</label>
    <label style="margin-left:12px"><input type="radio" name="commercial_effect" value="OPERATIONAL_ONLY" <?= $ce==='OPERATIONAL_ONLY'?'checked':'' ?> required> Operasional Saja</label>
    <label style="margin-left:12px"><input type="radio" name="commercial_effect" value="REPLACEMENT" <?= $ce==='REPLACEMENT'?'checked':'' ?> required> Penggantian Barang</label>
    <input class="field" name="commercial_effect_reason" minlength="8" required placeholder="Alasan klasifikasi/koreksi" style="margin-top:6px">
    <label style="display:block;margin-top:6px"><input type="checkbox" name="billing_unchanged_confirm" value="1"> Billing/PO tetap sama (wajib bila Operasional Saja)</label>
    <label style="display:block;margin-top:6px"><input type="checkbox" name="operational_override_confirm" value="1"> SYS override untuk catatan yang mengindikasikan revisi QTY/PO</label>
    <label style="display:block;margin-top:6px"><input type="checkbox" name="replacement_commit_confirm" value="1"> DO pengganti akan dibuat dari retur (wajib bila Penggantian Barang)</label>
    <label style="display:block;margin-top:6px"><input type="checkbox" name="commercial_effect_confirm" value="1" required> Saya sudah verifikasi dampak komersial.</label>
    <button class="btn-rmi" type="submit" style="margin-top:6px">Simpan Pengaruh Komersial</button>
  </form>
</details>
<?php endif; ?>
</div>
<?php if ($repDo): ?>
<div style="margin-top:8px"><b>DO Pengganti:</b> <a href="sales_do_view.php?id=<?= (int)$repDo['id'] ?>" target="_blank"><?= h($repDo['do_code']) ?></a> · <?= h($repDo['status']) ?></div>
<?php else: ?>
<?php if ($ce==='REPLACEMENT'): ?>
<div class="muted" style="margin-top:8px">Retur diklasifikasikan <b>REPLACEMENT</b> tetapi belum ada DO pengganti ter-link. Proses belum lengkap sampai CRM membuat DO Pengganti dari tombol khusus.</div>
<?php if ($scope['isCrm']): ?>
<div style="margin-top:10px">
  <a class="btn-rmi btn-green"
     href="sales_do.php?replacement_for=<?= (int)$rt['do_id'] ?>&return_id=<?= (int)$rt['id'] ?>">
     Buat DO Pengganti dari Retur Ini
  </a>
  <div class="muted" style="margin-top:6px">
    Wajib gunakan tombol ini. DO Pengganti hanya boleh berisi item/qty yang benar-benar diretur FINAL, merupakan fulfillment operasional, dan bukan penjualan baru.
  </div>
</div>
<?php endif; ?>
<?php elseif ($ce==='OPERATIONAL_ONLY'): ?>
<div class="muted" style="margin-top:8px"><b>OPERATIONAL_ONLY:</b> retur hanya memproses stok/operasional dan tidak membuka pembuatan DO pengganti. Jika bisnis sebenarnya membutuhkan barang pengganti, SYS harus ubah Pengaruh Komersial menjadi <b>REPLACEMENT</b> terlebih dahulu agar audit dan achievement tetap konsisten.</div>
<?php elseif ($ce==='REVERSAL'): ?>
<div class="muted" style="margin-top:8px"><b>REVERSAL:</b> retur mengurangi penjualan dan tidak membuka DO pengganti. Bila customer tetap menerima barang pengganti atas transaksi yang sama, klasifikasi harus dikoreksi menjadi <b>REPLACEMENT</b> terlebih dahulu.</div>
<?php else: ?>
<div class="muted" style="margin-top:8px"><b>NEEDS_REVIEW:</b> retur FINAL belum mempunyai klasifikasi komersial yang valid. SYS wajib memilih REVERSAL / OPERATIONAL_ONLY / REPLACEMENT sebelum proses lanjutan.</div>
<?php endif; ?>
<?php endif; ?>
</div><?php endif; ?>
</div><?php endforeach; ?></div>
<?php endif; ?>
<?php endif; ?>
</div>
<?php rmi_footer(); ?>
