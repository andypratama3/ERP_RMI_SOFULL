<?php
// GPS Permission Policy Fix
// Wajib sebelum output HTML agar browser mengizinkan navigator.geolocation.
if (!headers_sent()) {
    header_remove('Permissions-Policy');
    header_remove('Feature-Policy');
    header('Permissions-Policy: geolocation=(self "https://erp.rizqullahcorp.com" "https://rizqullahcorp.com"), camera=(self "https://erp.rizqullahcorp.com" "https://rizqullahcorp.com"), fullscreen=(self)', true);
    header("Feature-Policy: geolocation 'self' https://erp.rizqullahcorp.com https://rizqullahcorp.com; camera 'self' https://erp.rizqullahcorp.com https://rizqullahcorp.com; fullscreen 'self'", true);
}
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_login();

// --- Kebijakan lembur perusahaan -------------------------------------------------
// Setiap 2 jam penuh lembur = istirahat 15 menit.
// Nilai istirahat dihitung server-side agar tidak dapat dimanipulasi dari browser.
if (!function_exists('hrlp_company_overtime_break_minutes')) {
    function hrlp_company_overtime_break_minutes(int $grossMinutes): int {
        if ($grossMinutes <= 0) return 0;
        return intdiv($grossMinutes, 120) * 15;
    }
}
if (!function_exists('hrlp_company_overtime_gross_minutes')) {
    function hrlp_company_overtime_gross_minutes(string $date, string $start, string $end): int {
        $date = trim($date);
        $start = trim($start);
        $end = trim($end);
        if ($date === '' || $start === '' || $end === '') return 0;
        try {
            $startAt = new DateTimeImmutable($date . ' ' . $start . ':00');
            $endAt   = new DateTimeImmutable($date . ' ' . $end . ':00');
            if ($endAt <= $startAt) $endAt = $endAt->modify('+1 day');
            return max(0, (int) floor(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60));
        } catch (Throwable $e) {
            return 0;
        }
    }
}

$page_title = 'HRL Process Tower';
require_once __DIR__ . '/_layout_top.php';

// --- BRANCH approval resolver -------------------------------------------------
// BRANCH staff dari office mana pun tetap boleh membuat pengajuan.
// Jika pada office pengajuan tidak ada akun Manager BRANCH yang ACTIVE,
// tahap Manager dilewati secara otomatis ke MANAGER_APPROVED agar lanjut HRL.
// Dept lain tetap memakai alur lama Manager -> HRL.
if (!function_exists('hrlp_find_active_manager_for_request')) {
    function hrlp_find_active_manager_for_request(PDO $pdo, string $dept, string $office): ?array {
        $dept = strtoupper(trim($dept));
        $office = strtoupper(trim($office));
        if ($dept === '' || $office === '') return null;

        $sql = "SELECT id, username, role, level, department, office_code
                FROM master_system_login
                WHERE deleted_at IS NULL
                  AND UPPER(COALESCE(status,'')) = 'ACTIVE'
                  AND UPPER(COALESCE(department,'')) = ?
                  AND UPPER(COALESCE(office_code,'')) = ?
                  AND (
                        UPPER(COALESCE(role,'')) IN ('MANAGER','MGR')
                     OR UPPER(COALESCE(level,'')) IN ('MANAGER','MGR')
                  )
                ORDER BY id ASC
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$dept, $office]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('hrlp_auto_bypass_branch_manager')) {
    function hrlp_auto_bypass_branch_manager(PDO $pdo, int $requestId): bool {
        $st = $pdo->prepare("SELECT id, req_code, dept_code, office_code, status FROM hrl_requests WHERE id=? LIMIT 1");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return false;

        $dept = strtoupper(trim((string)($req['dept_code'] ?? '')));
        $office = strtoupper(trim((string)($req['office_code'] ?? '')));
        $status = strtoupper(trim((string)($req['status'] ?? '')));

        // Hanya BRANCH. Dept lain tidak disentuh.
        if ($dept !== 'BRANCH' || $status !== 'SUBMITTED') return false;
        // Office wajib jelas agar tidak salah bypass antar kantor.
        if ($office === '') return false;
        // Jika Manager BRANCH aktif tersedia di office yang sama, alur lama tetap berjalan.
        if (hrlp_find_active_manager_for_request($pdo, $dept, $office)) return false;

        $up = $pdo->prepare("UPDATE hrl_requests
                            SET status='MANAGER_APPROVED',
                                manager_approved_by='SYSTEM',
                                manager_approved_at=NOW(),
                                updated_at=NOW()
                            WHERE id=? AND status='SUBMITTED'");
        $up->execute([$requestId]);
        if ($up->rowCount() <= 0) return false;

        hrlp_audit('AUTO_BYPASS_MANAGER', [
            'id' => $requestId,
            'dept' => $dept,
            'office' => $office,
            'reason' => 'NO_ACTIVE_MANAGER_IN_REQUEST_OFFICE'
        ]);
        if (function_exists('master_audit')) {
            master_audit(
                $pdo,
                'hrl_process',
                'hrl_requests',
                'AUTO_BYPASS_MANAGER',
                $requestId,
                (string)($req['req_code'] ?? ('REQ#'.$requestId)),
                'Manager BRANCH tidak tersedia pada office '.$office.'; request otomatis diteruskan ke HRL.',
                ['dept'=>$dept, 'office'=>$office, 'reason'=>'NO_ACTIVE_MANAGER_IN_REQUEST_OFFICE']
            );
        }
        return true;
    }
}


// --- Tipe pengajuan (metadata + form: hanya tipe yang boleh dibuat user ini) ---
$allTypesMeta = hrlp_request_types_meta();
// KASBON/PINJAMAN: ditambahkan di sini agar tetap muncul walaupun metadata lama belum memuat tipe ini.
if (!isset($allTypesMeta['KASBON'])) {
    $allTypesMeta['KASBON'] = [
        'label' => 'Pinjaman / Kasbon',
        'icon'  => '' . rmi_icon("money") . '',
        'color' => '#f59e0b',
    ];
}

// SAKIT dipisahkan dari CUTI agar tidak mengurangi saldo cuti dan tidak melalui FIN.
if (!isset($allTypesMeta['SAKIT'])) {
    $allTypesMeta['SAKIT'] = ['label'=>'Sakit','icon'=>'' . rmi_icon("warn") . '','color'=>'#ef4444'];
}

// REKRUTMEN tidak dibuat sebagai pengajuan terpisah.
// Kebutuhan tenaga kerja diajukan melalui PERMINTAAN_KARYAWAN,
// sedangkan proses rekrutmen tetap menjadi pekerjaan internal HRL.
unset($allTypesMeta['REKRUTMEN']);
$hrlpCanCreateAllTypes = true;
$formTypesMeta = [];
foreach ($allTypesMeta as $k => $v) {
    if (true) {
        $formTypesMeta[$k] = $v;
    }
}
$preType = strtoupper(trim((string)($_GET['req_type'] ?? '')));
if ($preType === '' || !isset($formTypesMeta[$preType])) {
    $kFirst = array_key_first($formTypesMeta);
    $preType = $kFirst !== null ? (string) $kFirst : 'CUTI';
}

// --- POST: buat pengajuan ---
if (($_POST['action'] ?? '') === 'create') {
    csrf_check();

    $reqType    = strtoupper(trim((string)($_POST['req_type']    ?? '')));
    $title      = trim((string)($_POST['title']                  ?? ''));
    $desc       = trim((string)($_POST['description']            ?? ''));
    $start      = trim((string)($_POST['start_date']             ?? '')) ?: null;
    $end        = trim((string)($_POST['end_date']               ?? '')) ?: null;
    $amount     = is_numeric($_POST['amount'] ?? '') ? (float)$_POST['amount'] : 0.0;
    $loanTenor  = max(1, (int)($_POST['loan_tenor_months'] ?? 1));
    $loanStart  = trim((string)($_POST['loan_start_period_ym'] ?? ''));
    $loanInstall = is_numeric($_POST['loan_installment_amount'] ?? '') ? (float)$_POST['loan_installment_amount'] : 0.0;
    $gpsLat     = trim((string)($_POST['gps_lat']                ?? ''));
    $gpsLng     = trim((string)($_POST['gps_lng']                ?? ''));
    $gpsAcc     = trim((string)($_POST['gps_accuracy_m']         ?? ''));
    $submitMode = (string)($_POST['submit_mode']                 ?? 'draft');
    $otDate     = trim((string)($_POST['overtime_date'] ?? ''));
    $otStart    = trim((string)($_POST['overtime_start_time'] ?? ''));
    $otEnd      = trim((string)($_POST['overtime_end_time'] ?? ''));
    // Jangan percaya input break dari browser; dihitung otomatis sesuai kebijakan perusahaan.
    $otBreak    = 0;
    $otWork     = trim((string)($_POST['overtime_work_description'] ?? ''));
    $otData     = null;
    $staffPosition = trim((string)($_POST['staff_position_name'] ?? ''));
    $staffDivision = trim((string)($_POST['staff_division_name'] ?? ''));
    $staffLocation = trim((string)($_POST['staff_work_location'] ?? ''));
    $staffHeadcount = max(1, (int)($_POST['staff_headcount'] ?? 1));
    $staffStartDate = trim((string)($_POST['staff_expected_start_date'] ?? ''));
    $staffData = null;
    $sicknessData = null;
    $dept       = me_dept();
    $office     = me_office();

    if (is_admin_owner()) {
        $dept   = strtoupper(trim((string)($_POST['dept_code']   ?? $dept)))   ?: $dept;
        $office = strtoupper(trim((string)($_POST['office_code'] ?? $office))) ?: $office;
    }

    // HRL Process open-access: semua user login boleh CREATE semua tipe.

    try {
        if (!isset($formTypesMeta[$reqType])) {
            throw new RuntimeException('Tipe pengajuan tidak valid.');
        }
        if ($title === '') throw new RuntimeException('Judul wajib diisi.');

        if ($reqType === 'LEMBUR') {
            // Kebijakan perusahaan: setiap 2 jam penuh lembur mendapat istirahat 15 menit.
            // Break selalu dihitung ulang di server agar konsisten sampai data lembur tersimpan/payroll.
            $otGross = hrlp_company_overtime_gross_minutes($otDate, $otStart, $otEnd);
            if ($otGross <= 0) {
                throw new RuntimeException('Tanggal/Jam lembur tidak valid.');
            }
            $otBreak = hrlp_company_overtime_break_minutes($otGross);
            $otData = hrlp_overtime_calculate($otDate, $otStart, $otEnd, $otBreak);
            // Simpan jejak kebijakan pada payload jika helper menyediakan array fleksibel.
            if (is_array($otData)) {
                $otData['gross_minutes'] = $otGross;
                $otData['break_minutes'] = $otBreak;
                $otData['net_minutes'] = max(0, $otGross - $otBreak);
                $otData['break_policy'] = '15_MIN_PER_120_GROSS_MIN';
            }
            $start = substr($otData['start_at'], 0, 10);
            $end   = substr($otData['end_at'], 0, 10);
            if ($otWork === '') $otWork = $desc;
        }

        if ($reqType === 'PERMINTAAN_KARYAWAN') {
            $staffData = hrlp_staffing_validate($staffPosition, $staffDivision, $staffLocation, $staffHeadcount, $staffStartDate);
            $start = $staffData['expected_start_date'];
            $end = $staffData['expected_start_date'];
        }

        if ($reqType === 'SAKIT') {
            $sicknessData = hrlp_sickness_validate($_POST, $start, $end);
            $amount = 0.0; // sakit tidak boleh memicu gate FIN
        }

        if ($reqType === 'KASBON') {
            if ($amount <= 0) {
                throw new RuntimeException('Nominal Pinjaman / Kasbon wajib diisi dan harus lebih dari 0.');
            }
            if ($loanStart === '') {
                $loanStart = date('Y-m');
            }
            if (!preg_match('/^\d{4}-\d{2}$/', $loanStart)) {
                throw new RuntimeException('Mulai Periode Kasbon wajib format YYYY-MM.');
            }
            if ($loanInstall <= 0) {
                $loanInstall = $amount / max(1, $loanTenor);
            }

            // Data teknis disimpan di deskripsi agar tidak perlu mengubah tabel hrl_requests.
            $desc .= "\n\n[KASBON_DATA tenor_months={$loanTenor}; start_period_ym={$loanStart}; installment_amount=" . round($loanInstall, 2) . "]";
        }

        $hasPhoto  = isset($_FILES['proof_photo']) && (int)($_FILES['proof_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
        $hasAttach = false;
        if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
            foreach ((array)$_FILES['attachments']['error'] as $e) {
                if ((int)$e === UPLOAD_ERR_OK) { $hasAttach = true; break; }
            }
        }

        if ($submitMode === 'submit' && hrlp_requires_gps_photo($reqType)) {
            // Attachment bersifat opsional untuk semua tipe. Ketentuan GPS + foto tetap mengikuti alur lama.
            if (!$hasPhoto) throw new RuntimeException('Wajib ambil FOTO (selfie/bukti) saat submit.');
            if ($gpsLat === '' || $gpsLng === '') throw new RuntimeException('Wajib ambil GPS (lokasi) saat submit.');
        }

        // Transaction safe guard:
        // Hindari error "There is no active transaction" jika helper lain menutup transaksi.
        $hrlTxStarted = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $hrlTxStarted = true;
        }
        $status = ($submitMode === 'submit') ? 'SUBMITTED' : 'DRAFT';
        $stmt = $pdo->prepare("INSERT INTO hrl_requests
            (req_type,title,description,dept_code,office_code,start_date,end_date,amount,
             gps_lat,gps_lng,gps_accuracy_m,status,created_by,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([
            $reqType, $title, $desc, $dept ?: null, $office ?: null,
            $start, $end, $amount,
            ($gpsLat !== '' ? $gpsLat : null),
            ($gpsLng !== '' ? $gpsLng : null),
            ($gpsAcc !== '' ? (int)$gpsAcc : null),
            $status, me_username(),
        ]);
        $reqId   = (int)$pdo->lastInsertId();
        if ($reqType === 'LEMBUR' && is_array($otData)) {
            hrlp_overtime_upsert($pdo, $reqId, $otData, $otWork);
        }
        if ($reqType === 'PERMINTAAN_KARYAWAN' && is_array($staffData)) {
            hrlp_staffing_upsert($pdo, $reqId, $staffData);
        }
        if ($reqType === 'SAKIT' && is_array($sicknessData)) {
            hrlp_sickness_upsert($pdo, $reqId, $sicknessData);
        }

        $reqCode = 'HRL-' . date('Ymd') . '-' . str_pad((string)$reqId, 5, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE hrl_requests SET req_code=? WHERE id=?")->execute([$reqCode, $reqId]);

        if (function_exists('master_audit')) {
            master_audit($pdo,'hrl_process','hrl_requests','CREATE',$reqId,$reqCode,
                "HRL request: {$reqCode} ({$reqType})",['req_type'=>$reqType,'status'=>$status]);
        }

        $dir = hrlp_req_dir($reqId);
        if ($hasPhoto) {
            $saved = hrlp_save_upload($_FILES['proof_photo'], $dir, 'photo');
            $pdo->prepare("INSERT INTO hrl_request_files (request_id,kind,file_path,file_name,mime,size_bytes,uploaded_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$reqId,'PHOTO',$saved['path'],$saved['orig'],$saved['mime'],$saved['size'],me_username()]);
            $pdo->prepare("UPDATE hrl_requests SET photo_path=? WHERE id=?")->execute([$saved['path'],$reqId]);
        }
        if ($hasAttach) {
            $names = (array)($_FILES['attachments']['name']     ?? []);
            $tmps  = (array)($_FILES['attachments']['tmp_name'] ?? []);
            $errs  = (array)($_FILES['attachments']['error']    ?? []);
            $sizes = (array)($_FILES['attachments']['size']     ?? []);
            $types = (array)($_FILES['attachments']['type']     ?? []);
            for ($i = 0; $i < count($names); $i++) {
                if ((int)($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $one = ['name'=>$names[$i],'tmp_name'=>$tmps[$i],'error'=>$errs[$i],'size'=>$sizes[$i]??0,'type'=>$types[$i]??''];
                $saved = hrlp_save_upload($one, $dir, 'att');
                $pdo->prepare("INSERT INTO hrl_request_files (request_id,kind,file_path,file_name,mime,size_bytes,uploaded_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$reqId,'ATTACH',$saved['path'],$saved['orig'],$saved['mime'],$saved['size'],me_username()]);
            }
        }
        if ($submitMode === 'submit') {
            $pdo->prepare("UPDATE hrl_requests SET submitted_by=?,submitted_at=NOW() WHERE id=?")->execute([me_username(),$reqId]);
            hrlp_audit('SUBMIT_CREATE',['id'=>$reqId,'type'=>$reqType]);

            // Khusus BRANCH tanpa Manager aktif pada office yang sama: lanjut otomatis ke HRL.
            // BRANCH yang sudah memiliki Manager tetap mengikuti alur lama.
            hrlp_auto_bypass_branch_manager($pdo, $reqId);
        } else {
            hrlp_audit('CREATE_DRAFT',['id'=>$reqId,'type'=>$reqType]);
        }
        if ($hrlTxStarted && $pdo->inTransaction()) {
            $pdo->commit();
        }
        flash_set('Pengajuan berhasil dibuat: ' . $reqCode, 'success');
        rmi_redirect(u('/hrl_process/request_view.php?id=' . $reqId));
    } catch (Throwable $e) {
        // Jika muncul "There is no active transaction", biasanya karena transaksi sudah tertutup
        // oleh helper/audit/DB driver sebelum commit/rollback. Guard di bawah mencegah error itu.
        try {
            if (isset($hrlTxStarted) && $hrlTxStarted && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $rollbackError) {
            // Abaikan error rollback agar pesan asli tetap tampil.
        }
        flash_set('Gagal: ' . $e->getMessage(), 'danger');
        rmi_redirect(um('tower.php'));
    }
}

// --- Filters ---
$filterStatus = strtoupper(trim((string)($_GET['status'] ?? '')));
$filterType   = strtoupper(trim((string)($_GET['type']   ?? '')));
$q            = trim((string)($_GET['q']                 ?? ''));
$showDeleted  = (string)($_GET['show_deleted']           ?? '') === '1';

$canSeeAll  = is_admin_owner() || in_array(me_dept(), ['HRL','FIN'], true);
$canSeeDept = is_manager_like();

$where = []; $params = [];
if (!$showDeleted) $where[] = "deleted_at IS NULL";
if ($filterStatus !== '' && $filterStatus !== 'ALL') { $where[] = "status = ?";    $params[] = $filterStatus; }
if ($filterType   !== '' && $filterType   !== 'ALL') { $where[] = "req_type = ?";  $params[] = $filterType; }
if ($q !== '') {
    $where[] = "(req_code LIKE ? OR title LIKE ? OR created_by LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if (!$canSeeAll) {
    if ($canSeeDept) { $where[] = "dept_code = ?"; $params[] = me_dept(); }
    else             { $where[] = "created_by = ?"; $params[] = me_username(); }
}

$sql = "SELECT * FROM hrl_requests" . ($where ? " WHERE " . implode(" AND ", $where) : "") . " ORDER BY id DESC LIMIT 1000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$rows = array_values(array_filter($rows, static function (array $r): bool {
    return hrlp_can_view($r);
}));

// --- Summary counts ---
$counts = ['DRAFT'=>0,'SUBMITTED'=>0,'MANAGER_APPROVED'=>0,'HRL_APPROVED'=>0,'FIN_APPROVED'=>0,'PAID'=>0,'NEED_DOCUMENT'=>0,'REJECTED'=>0];
foreach ($rows as $r) {
    $st = strtoupper((string)($r['status']??''));
    if (isset($counts[$st])) $counts[$st]++;
}
$pending = $counts['SUBMITTED'] + $counts['MANAGER_APPROVED'] + $counts['HRL_APPROVED'] + $counts['FIN_APPROVED'] + $counts['NEED_DOCUMENT'];

// --- Pipeline progress summary ---
// Sebelumnya pipeline selalu hanya menandai step 1 aktif.
// Sekarang pipeline mengikuti status pengajuan yang tampil di daftar:
// DRAFT=0, SUBMITTED=1, MANAGER_APPROVED=2, HRL_APPROVED=3/selesai jika tidak perlu FIN,
// FIN_APPROVED=4, PAID=5.
$pipelineStage = count($rows) ? 1 : 0;
$pipelineNeedsFin = false;
foreach ($rows as $pipeRow) {
    $pipeStatus = strtoupper((string)($pipeRow['status'] ?? ''));
    $pipeNeedFin = hrlp_need_fin($pipeRow);
    if ($pipeNeedFin) {
        $pipelineNeedsFin = true;
    }

    $pipeStage = match ($pipeStatus) {
        'DRAFT'            => 0,
        'SUBMITTED'        => 1,
        'MANAGER_APPROVED' => 2,
        'HRL_APPROVED'     => $pipeNeedFin ? 3 : 5,
        'FIN_APPROVED'     => 4,
        'PAID'             => 5,
        'REJECTED'         => 0,
        default            => 0,
    };

    if ($pipeStage > $pipelineStage) {
        $pipelineStage = $pipeStage;
    }
}

function hrl_pipeline_dot_class(int $step, int $stage): string {
    if ($stage > $step) return 'done';
    if ($stage === $step) return 'active';
    return '';
}
function hrl_pipeline_line_class(int $afterStep, int $stage): string {
    return $stage > $afterStep ? 'done' : '';
}

// --- Helpers ---
function hrl_status_meta(string $st): array {
    return match(strtoupper($st)) {
        'DRAFT'            => ['label'=>'Draft',           'cls'=>'hrl-st-draft',    'dot'=>'●'],
        'SUBMITTED'        => ['label'=>'Submitted',       'cls'=>'hrl-st-submitted','dot'=>'●'],
        'MANAGER_APPROVED' => ['label'=>'Mgr ' . rmi_icon("tick") . '',          'cls'=>'hrl-st-mgr',      'dot'=>'●'],
        'HRL_APPROVED'     => ['label'=>'HRL ' . rmi_icon("tick") . '',          'cls'=>'hrl-st-hrl',      'dot'=>'●'],
        'FIN_APPROVED'     => ['label'=>'FIN ' . rmi_icon("tick") . '',          'cls'=>'hrl-st-fin',      'dot'=>'●'],
        'PAID'             => ['label'=>'Selesai',         'cls'=>'hrl-st-paid',     'dot'=>'●'],
        'NEED_DOCUMENT'    => ['label'=>'Perlu Dokumen',    'cls'=>'hrl-st-submitted','dot'=>'●'],
        'REJECTED'         => ['label'=>'Ditolak',         'cls'=>'hrl-st-rejected', 'dot'=>'●'],
        default            => ['label'=>$st,               'cls'=>'hrl-st-draft',    'dot'=>'●'],
    };
}
function hrl_next_pic(array $r): string {
    global $pdo;
    $st     = strtoupper((string)($r['status'] ?? ''));
    $dept   = strtoupper((string)($r['dept_code'] ?? ''));
    $office = strtoupper((string)($r['office_code'] ?? ''));

    if ($st === 'SUBMITTED' && $dept === 'BRANCH' && $office !== '') {
        $mgr = hrlp_find_active_manager_for_request($pdo, $dept, $office);
        if (!$mgr) return 'HRL — Manager BRANCH tidak tersedia';
    }

    return match($st) {
        'DRAFT'            => 'Pemohon — belum disubmit',
        'SUBMITTED'        => "Manager {$dept}",
        'MANAGER_APPROVED' => 'HRL',
        'HRL_APPROVED'     => hrlp_need_fin($r) ? 'FIN' : '— Selesai',
        'FIN_APPROVED'     => 'FIN (PAID)',
        'PAID'             => '— Selesai',
        'NEED_DOCUMENT'    => 'Pemohon (Lengkapi Dokumen)',
        'REJECTED'         => 'Pemohon (Revisi)',
        default            => '—',
    };
}
?>

<style>
/* ── Tower layout ─────────────────────────────── */
.hrl-grid { display:grid; grid-template-columns:380px 1fr; gap:20px; align-items:start }
@media(max-width:900px){ .hrl-grid{ grid-template-columns:1fr } }

/* ── Summary strip ─────────────────────────────── */
.hrl-summary { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px }
.hrl-sum-card { flex:1; min-width:110px; background:var(--rmi-card,#1a2235);
    border:1px solid var(--rmi-border,rgba(255,255,255,.1)); border-radius:12px;
    padding:14px 16px; display:flex; flex-direction:column; gap:4px }
.hrl-sum-num  { font-size:26px; font-weight:700; line-height:1 }
.hrl-sum-lbl  { font-size:11px; color:var(--rmi-muted,#9ca3af); text-transform:uppercase; letter-spacing:.5px }
.hrl-sum-card.pending .hrl-sum-num { color:#f59e0b }
.hrl-sum-card.done    .hrl-sum-num { color:#10b981 }
.hrl-sum-card.reject  .hrl-sum-num { color:#ef4444 }
.hrl-sum-card.total   .hrl-sum-num { color:#60a5fa }

/* ── Form card ─────────────────────────────── */
.hrl-form-card { background:var(--rmi-card,#1a2235);
    border:1px solid var(--rmi-border,rgba(255,255,255,.1)); border-radius:14px; overflow:hidden }
.hrl-form-head { padding:14px 18px; border-bottom:1px solid var(--rmi-border,rgba(255,255,255,.08));
    font-weight:600; font-size:14px; display:flex; align-items:center; gap:8px }
.hrl-form-body { padding:18px }

/* ── Type selector ─────────────────────────────── */
.hrl-type-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:7px; margin-bottom:4px }
.hrl-type-btn  { display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:4px; padding:10px 4px; border-radius:10px; cursor:pointer; border:2px solid transparent;
    background:rgba(255,255,255,.05); transition:all .15s; font-size:11px; color:var(--rmi-muted,#9ca3af);
    text-align:center; line-height:1.3 }
.hrl-type-btn .ico { font-size:20px }
.hrl-type-btn:hover { background:rgba(255,255,255,.1); color:var(--rmi-text,#e8ecf4) }
.hrl-type-btn.active { border-color:currentColor; color:#fff; background:rgba(255,255,255,.1) }
#hrlTypeInput { display:none }

/* ── GPS widget ─────────────────────────────── */
.hrl-gps-row { display:flex; gap:8px; align-items:center }
.hrl-gps-status { font-size:12px; color:var(--rmi-muted,#9ca3af); margin-top:4px; min-height:18px }
.hrl-gps-status.ok { color:#10b981 }
.hrl-gps-status.err { color:#ef4444 }

/* ── Pipeline ─────────────────────────────── */
.hrl-pipeline { display:flex; align-items:center; gap:0; flex-wrap:nowrap; overflow-x:auto; padding:4px 0 8px }
.hrl-pip-step { display:flex; flex-direction:column; align-items:center; min-width:70px; text-align:center }
.hrl-pip-dot  { width:30px; height:30px; border-radius:50%; background:rgba(255,255,255,.1);
    border:2px solid rgba(255,255,255,.15); display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:700; color:rgba(255,255,255,.4); flex-shrink:0 }
.hrl-pip-dot.active { background:#2563eb; border-color:#3b82f6; color:#fff }
.hrl-pip-dot.done   { background:#059669; border-color:#10b981; color:#fff }
.hrl-pip-lbl  { font-size:10px; margin-top:5px; color:var(--rmi-muted,#9ca3af); white-space:nowrap }
.hrl-pip-line { flex:1; height:2px; background:rgba(255,255,255,.1); min-width:16px }
.hrl-pip-line.done { background:#059669 }

/* ── Status badges ─────────────────────────────── */
.hrl-badge     { display:inline-flex; align-items:center; gap:5px; padding:3px 9px;
    border-radius:20px; font-size:11px; font-weight:600; white-space:nowrap }
.hrl-st-draft      { background:rgba(100,116,139,.2); color:#94a3b8 }
.hrl-st-submitted  { background:rgba(245,158,11,.15); color:#fbbf24 }
.hrl-st-mgr        { background:rgba(59,130,246,.15); color:#60a5fa }
.hrl-st-hrl        { background:rgba(139,92,246,.15); color:#a78bfa }
.hrl-st-fin        { background:rgba(20,184,166,.15); color:#2dd4bf }
.hrl-st-paid       { background:rgba(16,185,129,.15); color:#34d399 }
.hrl-st-rejected   { background:rgba(239,68,68,.15);  color:#f87171 }

/* ── Table ─────────────────────────────── */
.hrl-tbl-wrap { overflow-x:auto }
.hrl-tbl-wrap table.dataTable { width:100%!important }
.type-chip { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600;
    background:rgba(255,255,255,.08); color:var(--rmi-text,#e8ecf4) }

/* ── Right panel ─────────────────────────────── */
.hrl-right-card { background:var(--rmi-card,#1a2235);
    border:1px solid var(--rmi-border,rgba(255,255,255,.1)); border-radius:14px; overflow:hidden }
.hrl-right-head { padding:14px 18px; border-bottom:1px solid var(--rmi-border,rgba(255,255,255,.08));
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px }
.hrl-filter-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center }
.hrl-right-body { padding:16px }

/* ── Alert rule card ─────────────────────────────── */
.hrl-rule-card { background:rgba(37,99,235,.08); border:1px solid rgba(59,130,246,.2);
    border-radius:12px; padding:14px 16px; margin-top:14px }
.hrl-rule-card li { font-size:12px; color:var(--rmi-muted,#9ca3af); margin-bottom:5px; line-height:1.6 }
.hrl-rule-card li b { color:var(--rmi-text,#e8ecf4) }
</style>

<!-- ── Summary strip ── -->
<div class="hrl-summary">
  <div class="hrl-sum-card pending">
    <div class="hrl-sum-num"><?= $pending ?></div>
    <div class="hrl-sum-lbl">Menunggu</div>
  </div>
  <div class="hrl-sum-card">
    <div class="hrl-sum-num" style="color:#94a3b8"><?= $counts['DRAFT'] ?></div>
    <div class="hrl-sum-lbl">Draft</div>
  </div>
  <div class="hrl-sum-card done">
    <div class="hrl-sum-num"><?= $counts['PAID'] ?></div>
    <div class="hrl-sum-lbl">Selesai</div>
  </div>
  <div class="hrl-sum-card reject">
    <div class="hrl-sum-num"><?= $counts['REJECTED'] ?></div>
    <div class="hrl-sum-lbl">Ditolak</div>
  </div>
  <div class="hrl-sum-card total">
    <div class="hrl-sum-num"><?= count($rows) ?></div>
    <div class="hrl-sum-lbl">Total</div>
  </div>
</div>

<!-- ── Pipeline ── -->
<div style="background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));
            border-radius:14px;padding:14px 20px;margin-bottom:20px">
  <div style="font-size:11px;font-weight:600;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;
              letter-spacing:.5px;margin-bottom:10px">Alur Persetujuan</div>
  <div style="font-size:11px;color:var(--rmi-muted,#9ca3af);margin:-4px 0 10px">
    Mengikuti status pengajuan yang tampil di daftar.
  </div>
  <div class="hrl-pipeline">
    <div class="hrl-pip-step">
      <div class="hrl-pip-dot <?= hrl_pipeline_dot_class(1, $pipelineStage) ?>">1</div>
      <div class="hrl-pip-lbl">Staff<br>Buat & Submit</div>
    </div>
    <div class="hrl-pip-line <?= hrl_pipeline_line_class(1, $pipelineStage) ?>"></div>

    <div class="hrl-pip-step">
      <div class="hrl-pip-dot <?= hrl_pipeline_dot_class(2, $pipelineStage) ?>">2</div>
      <div class="hrl-pip-lbl">Manager<br>Dept</div>
    </div>
    <div class="hrl-pip-line <?= hrl_pipeline_line_class(2, $pipelineStage) ?>"></div>

    <div class="hrl-pip-step">
      <div class="hrl-pip-dot <?= hrl_pipeline_dot_class(3, $pipelineStage) ?>">3</div>
      <div class="hrl-pip-lbl">HRL<br>Review</div>
    </div>
    <div class="hrl-pip-line <?= hrl_pipeline_line_class(3, $pipelineStage) ?>"></div>

    <div class="hrl-pip-step">
      <div class="hrl-pip-dot <?= hrl_pipeline_dot_class(4, $pipelineStage) ?>"><?= $pipelineNeedsFin ? '4' : 'N/A' ?></div>
      <div class="hrl-pip-lbl">FIN<br><?= $pipelineNeedsFin ? '(jika perlu)' : '(tidak perlu)' ?></div>
    </div>
    <div class="hrl-pip-line <?= hrl_pipeline_line_class(4, $pipelineStage) ?>"></div>

    <div class="hrl-pip-step">
      <div class="hrl-pip-dot <?= $pipelineStage >= 5 ? 'done' : '' ?>"><?=rmi_icon('tick')?></div>
      <div class="hrl-pip-lbl">Selesai<br>/ PAID</div>
    </div>
  </div>
</div>

<!-- ── Main grid ── -->
<div class="hrl-grid">

  <!-- ── Form kiri ── -->
  <div>
    <?php if ($formTypesMeta === []): ?>
    <div class="hrl-form-card">
      <div class="hrl-form-head"><span><?=rmi_icon('memo')?></span> Buat pengajuan</div>
      <div class="hrl-form-body">
        <div class="alert alert-warning mb-0" style="font-size:13px">
          Anda tidak memiliki izin <b>CREATE</b> untuk tipe pengajuan manapun. Hubungi admin/SYS untuk mengatur permission
          <span class="mono">HRL.REQ_&lt;TIPE&gt;_CREATE</span> di RBAC Center (atau izin legacy <span class="mono">HRL.PROCESS_EDIT</span>).
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="hrl-form-card">
      <div class="hrl-form-head">
        <span><?=rmi_icon('memo')?></span> Buat Pengajuan Baru
      </div>
      <div class="hrl-form-body">
        <form method="post" enctype="multipart/form-data" id="hrlForm">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action"  value="create">
          <input type="hidden" name="req_type" id="hrlTypeInput" value="<?= h($preType) ?>" required>

          <!-- Tipe selector visual -->
          <div class="mb-3">
            <div class="form-label" style="font-size:12px;font-weight:600;margin-bottom:8px">Pilih Tipe Pengajuan</div>
            <div class="hrl-type-grid" id="typeGrid">
              <?php foreach ($formTypesMeta as $k => $v): ?>
              <div class="hrl-type-btn <?= ($k === $preType ? 'active' : '') ?>"
                   data-type="<?= h($k) ?>" style="--tc:<?= h($v['color']) ?>"
                   onclick="selectType('<?= h($k) ?>', this)">
                <span class="ico"><?= $v['icon'] ?></span>
                <span><?= h($v['label']) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <div id="typeNote" class="help mt-1" style="font-size:11px"></div>
          </div>

          <!-- Judul -->
          <div class="mb-3">
            <label class="form-label">Judul / Alasan <span style="color:#ef4444">*</span></label>
            <input class="form-control" name="title" id="hrlTitle"
                   placeholder="contoh: Cuti tahunan / Lembur closing bulan ini" required>
          </div>

          <!-- Field khusus LEMBUR -->
          <div class="mb-3" id="overtimeFields" style="display:none">
            <div class="row g-2">
              <div class="col-12"><label class="form-label">Tanggal Lembur <span style="color:#ef4444">*</span></label><input type="date" class="form-control" name="overtime_date"></div>
              <div class="col-6"><label class="form-label">Jam Mulai <span style="color:#ef4444">*</span></label><input type="time" class="form-control" name="overtime_start_time"></div>
              <div class="col-6"><label class="form-label">Jam Selesai <span style="color:#ef4444">*</span></label><input type="time" class="form-control" name="overtime_end_time"></div>
              <div class="col-6"><label class="form-label">Istirahat Otomatis</label><input type="text" class="form-control" id="overtimeBreakPreview" value="0 menit" readonly><div class="help mt-1" style="font-size:11px">15 menit untuk setiap 2 jam penuh lembur.</div></div>
              <div class="col-6"><label class="form-label">Durasi Bersih</label><input type="text" class="form-control" id="overtimeDurationPreview" value="0 jam 0 menit" readonly></div>
              <div class="col-12"><label class="form-label">Pekerjaan Lembur</label><textarea class="form-control" name="overtime_work_description" rows="2" placeholder="Rincian pekerjaan yang dilakukan"></textarea></div>
            </div>
            <div class="help mt-1" style="font-size:11px">Jika jam selesai lebih kecil dari jam mulai, sistem menganggap selesai pada hari berikutnya. Istirahat dihitung otomatis: <b>15 menit per 2 jam penuh lembur</b>.</div>
          </div>

          <!-- Field khusus SAKIT -->
          <div class="mb-3" id="sicknessFields" style="display:none">
            <div class="card rmi-card"><div class="card-body">
              <div class="fw-semibold mb-2">Detail Pengajuan Sakit</div>
              <div class="row g-2">
                <div class="col-6"><label class="form-label">Durasi Hari</label><select class="form-select" name="sick_day_type"><option value="FULL_DAY">Sehari penuh</option><option value="HALF_DAY">Setengah hari</option></select></div>
                <div class="col-6"><label class="form-label">Perkiraan Kembali Bekerja *</label><input type="date" class="form-control" name="sick_expected_return_date"></div>
                <div class="col-12"><label class="form-label">Kondisi Umum/Keluhan Singkat *</label><textarea class="form-control" name="sick_general_condition" rows="2" placeholder="Tidak perlu menulis diagnosis medis terperinci"></textarea></div>
                <div class="col-12"><label class="form-label">Lokasi Pemeriksaan/Perawatan *</label><input class="form-control" name="sick_examination_location" placeholder="Rumah / Klinik / Puskesmas / Rumah Sakit"></div>
                <div class="col-12"><label class="form-label">Pekerjaan yang Perlu Diserahterimakan</label><textarea class="form-control" name="sick_handover_work" rows="2"></textarea></div>
                <div class="col-6"><label class="form-label">Penerima Pekerjaan</label><input class="form-control" name="sick_handover_to"></div>
                <div class="col-6"><label class="form-label">Nomor Kontak Selama Sakit *</label><input class="form-control" name="sick_contact"></div>
                <div class="col-6"><label><input type="checkbox" name="sick_emergency" value="1"> Kondisi darurat/IGD</label></div>
                <div class="col-6"><label><input type="checkbox" name="sick_inpatient" value="1"> Rawat inap</label></div>
              </div>
              <div class="help mt-2">Sakit 2 hari kerja berturut-turut atau lebih, rawat inap, atau IGD: surat dokter/bukti rawat wajib. Informasi medis rinci hanya untuk HRL.</div>
            </div></div>
          </div>

          <!-- Periode -->
          <div class="mb-3">
            <label class="form-label">Periode</label>
            <div class="row g-2">
              <div class="col">
                <input type="date" class="form-control" name="start_date" placeholder="Mulai">
              </div>
              <div class="col">
                <input type="date" class="form-control" name="end_date" placeholder="Selesai">
              </div>
            </div>
          </div>

          <!-- Nominal -->
          <div class="mb-3" id="nominalRow">
            <label class="form-label">Nominal <span style="color:var(--rmi-muted,#9ca3af);font-size:11px">(opsional — jika ada nilai uang)</span></label>
            <div style="position:relative">
              <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);
                           color:var(--rmi-muted,#9ca3af);font-size:13px">Rp</span>
              <input type="number" step="1000" min="0" class="form-control" name="amount" value="0"
                     style="padding-left:34px">
            </div>
            <div class="help mt-1" style="font-size:11px">Nominal > 0 → otomatis perlu approval FIN</div>
          </div>

          <!-- Field khusus KASBON / PINJAMAN -->
          <div class="mb-3" id="kasbonLoanFields" style="display:none">
            <div class="row g-2">
              <div class="col-4">
                <label class="form-label">Tenor Kasbon</label>
                <input type="number" min="1" class="form-control" name="loan_tenor_months" value="1">
              </div>
              <div class="col-4">
                <label class="form-label">Mulai Potong</label>
                <input type="month" class="form-control" name="loan_start_period_ym" value="<?= h(date('Y-m')) ?>">
              </div>
              <div class="col-4">
                <label class="form-label">Cicilan / bulan</label>
                <input type="number" step="0.01" min="0" class="form-control" name="loan_installment_amount" placeholder="auto">
              </div>
            </div>
            <div class="help mt-1" style="font-size:11px">
              Khusus Pinjaman/Kasbon: setelah Manager → HRL → FIN → PAID, data otomatis dibuat ke Payroll Loans.
            </div>
          </div>

          <!-- Field khusus PERMINTAAN KARYAWAN -->
          <div class="mb-3" id="staffingFields" style="display:none">
            <div class="card rmi-card"><div class="card-body">
              <div class="fw-semibold mb-2">Detail Kebutuhan Karyawan</div>
              <div class="row g-2">
                <div class="col-12"><label class="form-label">Jabatan <span style="color:#ef4444">*</span></label><input class="form-control" name="staff_position_name" placeholder="contoh: Staff Warehouse" maxlength="150"></div>
                <div class="col-6"><label class="form-label">Divisi / Departemen <span style="color:#ef4444">*</span></label><input class="form-control" name="staff_division_name" placeholder="contoh: WQS" maxlength="100"></div>
                <div class="col-6"><label class="form-label">Lokasi Kerja <span style="color:#ef4444">*</span></label><input class="form-control" name="staff_work_location" placeholder="contoh: Depo Samarinda" maxlength="150"></div>
                <div class="col-5"><label class="form-label">Jumlah Kebutuhan <span style="color:#ef4444">*</span></label><input type="number" min="1" max="999" class="form-control" name="staff_headcount" value="1"></div>
                <div class="col-7"><label class="form-label">Tanggal Mulai Kerja <span style="color:#ef4444">*</span></label><input type="date" class="form-control" name="staff_expected_start_date"></div>
              </div>
            </div></div>
          </div>

          <!-- Keterangan -->
          <div class="mb-3">
            <label class="form-label">Keterangan</label>
            <textarea class="form-control" name="description" rows="2"
                      placeholder="Detail kebutuhan / alasan / catatan tambahan"></textarea>
          </div>

          <?php if (is_admin_owner()): ?>
          <!-- Admin override -->
          <div class="mb-3">
            <label class="form-label" style="font-size:11px;color:var(--rmi-muted,#9ca3af)">Override Dept / Office (admin)</label>
            <div class="row g-2">
              <div class="col">
                <input class="form-control form-control-sm" name="dept_code"
                       placeholder="CRM/FIN/HRL..." value="<?= h(me_dept()) ?>">
              </div>
              <div class="col">
                <input class="form-control form-control-sm" name="office_code"
                       placeholder="BGR/BKS/..." value="<?= h(me_office()) ?>">
              </div>
            </div>
          </div>
          <?php else: ?>
          <div class="mb-3">
            <div class="help" style="font-size:11px">
              Akun:
              <span class="badge-pill"><?= h(me_dept()   ?: '-') ?></span>
              <span class="badge-pill"><?= h(me_office() ?: '-') ?></span>
            </div>
          </div>
          <?php endif; ?>

          <!-- Attachment -->
          <div class="mb-3">
            <label class="form-label">
              Attachment <span style="color:var(--rmi-muted,#9ca3af);font-size:11px">(opsional)</span>
            </label>
            <input type="file" class="form-control" name="attachments[]" multiple
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp">
            <div class="help mt-1" style="font-size:11px">Opsional. PDF/DOC/XLS/JPG/PNG — boleh lebih dari 1 file.</div>
          </div>

          <!-- Foto -->
          <div class="mb-3" id="photoRow">
            <label class="form-label">
              Foto Bukti
              <span id="photoRequired" style="color:#ef4444;font-size:11px"> *</span>
            </label>
            <input type="file" class="form-control" name="proof_photo"
                   accept="image/*" capture="user">
            <div class="help mt-1" style="font-size:11px" id="photoNote">Selfie / foto bukti. Wajib saat submit (kecuali Perjadin).</div>
          </div>

          <!-- GPS -->
          <div class="mb-3" id="gpsRow">
            <label class="form-label">
              Lokasi GPS
              <span id="gpsRequired" style="color:#ef4444;font-size:11px"> *</span>
            </label>
            <div class="hrl-gps-row">
              <button type="button" class="btn btn-ghost btn-sm" id="btnGeo" style="white-space:nowrap">
                <?=rmi_icon('target')?> Ambil GPS
              </button>
              <input class="form-control form-control-sm" name="gps_lat" id="gps_lat"
                     placeholder="Lat" readonly style="flex:1">
              <input class="form-control form-control-sm" name="gps_lng" id="gps_lng"
                     placeholder="Lng" readonly style="flex:1">
            </div>
            <input type="hidden" name="gps_accuracy_m" id="gps_acc">
            <div class="hrl-gps-status" id="geoHelp">Klik "Ambil GPS" lalu izinkan lokasi.</div>
          </div>

          <div class="d-grid gap-2 mt-4">
            <button class="btn btn-ghost" name="submit_mode" value="draft">
              <?=rmi_icon('doc')?> Simpan Draft
            </button>
            <button class="btn btn-rmi" name="submit_mode" value="submit">
              <?=rmi_icon('zap')?> Submit Pengajuan
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Aturan -->
    <div class="hrl-rule-card">
      <div style="font-size:12px;font-weight:600;color:var(--rmi-text,#e8ecf4);margin-bottom:8px"><?=rmi_icon('memo')?> Aturan Pengajuan</div>
      <ul style="margin:0;padding-left:18px">
        <li><b>Attachment opsional</b> untuk seluruh tipe pengajuan.</li>
        <li><b>GPS + Foto</b> wajib untuk tipe yang mengikuti alur lama; <b>Perjadin dan Sakit</b> bersifat opsional.</li>
        <li><b>Sakit</b> tidak mengurangi saldo cuti dan tidak melalui FIN.</li>
        <li>Nominal > 0 → otomatis ke gate <b>FIN</b> setelah HRL approve.</li>
        <li>TTD digital saat approve: konfirmasi <b>Password / PIN</b>.</li>
      </ul>
      <div style="margin-top:10px">
        <a href="<?= h(um('panduan.php')) ?>" class="btn btn-ghost btn-sm" style="font-size:12px">
          <?=rmi_icon('books')?> Baca Panduan Lengkap →
        </a>
      </div>
    </div>
  </div>

  <!-- ── Tabel kanan ── -->
  <div class="hrl-right-card">
    <div class="hrl-right-head">
      <div style="font-weight:600;font-size:14px">Daftar Pengajuan</div>
      <form class="hrl-filter-bar" method="get">
        <select class="form-select form-select-sm" name="status" style="width:145px">
          <option value="ALL">Semua Status</option>
          <?php foreach (array_keys($counts) as $st): ?>
            <option value="<?= h($st) ?>" <?= ($filterStatus===$st?'selected':'') ?>>
              <?= h($st) ?>
              <?php if ($counts[$st] > 0): ?>(<?= $counts[$st] ?>)<?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" name="type" style="width:160px">
          <option value="ALL">Semua Tipe</option>
          <?php foreach ($allTypesMeta as $k=>$v): ?>
            <option value="<?= h($k) ?>" <?= ($filterType===$k?'selected':'') ?>>
              <?= $v['icon'] ?> <?= h($v['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input class="form-control form-control-sm" name="q" value="<?= h($q) ?>"
               placeholder="Cari code/judul/user" style="width:180px">
        <button class="btn btn-soft btn-sm">Filter</button>
        <a class="btn btn-ghost btn-sm" href="<?= h(um('tower.php')) ?>">Reset</a>
      </form>
    </div>

    <div class="hrl-right-body">
      <?php if (empty($rows)): ?>
        <div style="text-align:center;padding:48px 0;color:var(--rmi-muted,#9ca3af)">
          <div style="font-size:36px;margin-bottom:12px"><?=rmi_icon('clipboard')?></div>
          <div style="font-size:14px;margin-bottom:6px">Belum ada pengajuan</div>
          <div style="font-size:12px">Buat pengajuan baru menggunakan form di sebelah kiri.</div>
        </div>
      <?php else: ?>
      <div class="hrl-tbl-wrap">
        <table id="tbl" class="display" style="width:100%">
          <thead>
            <tr>
              <th>Code</th>
              <th>Tipe</th>
              <th>Judul</th>
              <th>Dept</th>
              <th>Status</th>
              <th>Next PIC</th>
              <th>Nominal</th>
              <th>Oleh</th>
              <th>Tanggal</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $st  = strtoupper((string)($r['status']??''));
              $meta = hrl_status_meta($st);
              $typeInfo = $allTypesMeta[$r['req_type']] ?? ['icon'=>'' . rmi_icon("doc") . '','label'=>$r['req_type'],'color'=>'#9ca3af'];
              $amount = (float)($r['amount']??0);
            ?>
            <tr>
              <td class="mono" style="font-size:12px;white-space:nowrap">
                <?= h($r['req_code'] ?: ('#'.$r['id'])) ?>
              </td>
              <td>
                <span class="type-chip">
                  <?= $typeInfo['icon'] ?> <?= h($typeInfo['label']) ?>
                </span>
              </td>
              <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= h($r['title']) ?>
              </td>
              <td><span class="badge-pill"><?= h($r['dept_code']??'-') ?></span></td>
              <td>
                <span class="hrl-badge <?= $meta['cls'] ?>">
                  <?= $meta['dot'] ?> <?= h($meta['label']) ?>
                </span>
              </td>
              <td style="font-size:12px;color:var(--rmi-muted,#9ca3af)">
                <?= h(hrl_next_pic($r)) ?>
              </td>
              <td class="mono" style="font-size:12px">
                <?= $amount > 0 ? 'Rp&nbsp;' . number_format($amount, 0, ',', '.') : '<span style="color:var(--rmi-muted)">—</span>' ?>
              </td>
              <td style="font-size:12px"><?= h($r['created_by']) ?></td>
              <td class="mono" style="font-size:12px;white-space:nowrap">
                <?= h(substr((string)($r['created_at']??''), 0, 10)) ?>
              </td>
              <td>
                <a class="btn btn-sm btn-soft" href="<?= h(u('/hrl_process/request_view.php?id='.(int)$r['id'])) ?>">
                  Buka
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /hrl-grid -->

<script>
// ── Tipe selector (global — dipanggil dari onclick di HTML) ──
var _hrlTypeNotes = {
    'CUTI':                'Cuti tahunan. Mengurangi saldo cuti setelah disetujui HRL.',
    'SAKIT':               'Pengajuan sakit terpisah dari cuti. Tidak mengurangi saldo cuti, GPS/foto opsional, validasi akhir oleh HRL.',
    'IZIN':                'Izin tidak masuk/keluar lebih awal. Wajib GPS + Foto saat submit.',
    'LEMBUR':              'Pengajuan lembur + nominal jika ada. Wajib GPS + Foto. Otomatis ke FIN.',
    'PERJADIN':            'Form perjalanan dinas. Attachment, foto, dan GPS bersifat opsional.',
    'PERMINTAAN_KARYAWAN': 'Permintaan tenaga kerja: jabatan, divisi, lokasi, jumlah kebutuhan, dan tanggal mulai kerja. Wajib GPS + Foto. Otomatis ke FIN.',
    'KENAIKAN_GAJI':       'Pengajuan kenaikan gaji — review HRL + FIN. Wajib GPS + Foto.',
    'KASBON':              'Pengajuan Pinjaman / Kasbon. Wajib nominal, tenor, dan mulai periode potong. Alur: Manager → HRL → FIN → PAID → Payroll.',
};

var _hrlTitles = {
  CUTI:'contoh: Cuti tahunan 3 hari',
  SAKIT:'contoh: Sakit tanggal 20–21 Juli 2026',
  IZIN:'contoh: Izin sakit hari ini / Izin keperluan keluarga',
  LEMBUR:'contoh: Lembur closing bulan Maret 2026',
  PERJADIN:'contoh: Perjalanan Dinas Jakarta – Bogor',
  PERMINTAAN_KARYAWAN:'contoh: Kebutuhan Staff Warehouse Depo Samarinda',
  KENAIKAN_GAJI:'contoh: Pengajuan kenaikan gaji periode Q2 2026',
  KASBON:'contoh: Pengajuan kasbon operasional / pinjaman karyawan',
};

// Global — dipanggil dari onclick="selectType(...)" di HTML
function selectType(key, el) {
  document.querySelectorAll('.hrl-type-btn').forEach(function(b){ b.classList.remove('active'); });
  el.classList.add('active');
  var inp = document.getElementById('hrlTypeInput');
  if (inp) inp.value = key;
  var note = document.getElementById('typeNote');
  if (note) note.textContent = _hrlTypeNotes[key] || '';
  updateGpsPhotoVisibility(key);
  var titleInp = document.getElementById('hrlTitle');
  if (titleInp) titleInp.placeholder = _hrlTitles[key] || 'Judul pengajuan';
}

// Global — dipanggil dari selectType dan DOMContentLoaded
function updateGpsPhotoVisibility(key) {
  var isPerjadin = (key === 'PERJADIN');
  var isKasbon   = (key === 'KASBON');
  var isOvertime = (key === 'LEMBUR');
  var isStaffing = (key === 'PERMINTAAN_KARYAWAN');
  var isSickness = (key === 'SAKIT');
  var sicknessBox = document.getElementById('sicknessFields');
  if (sicknessBox) sicknessBox.style.display = isSickness ? 'block' : 'none';
  var staffingBox = document.getElementById('staffingFields');
  if (staffingBox) staffingBox.style.display = isStaffing ? 'block' : 'none';
  var overtimeBox = document.getElementById('overtimeFields');
  if (overtimeBox) overtimeBox.style.display = isOvertime ? 'block' : 'none';
  var kasbonBox  = document.getElementById('kasbonLoanFields');
  if (kasbonBox) kasbonBox.style.display = isKasbon ? 'block' : 'none';
  var gpsRow    = document.getElementById('gpsRow');
  var photoRow  = document.getElementById('photoRow');
  var gpsReq    = document.getElementById('gpsRequired');
  var photoReq  = document.getElementById('photoRequired');
  var photoNote = document.getElementById('photoNote');
  if (isPerjadin || isSickness) {
    if (gpsRow)    gpsRow.style.opacity   = '0.4';
    if (photoRow)  photoRow.style.opacity = '0.4';
    if (gpsReq)    gpsReq.textContent = ' (tidak wajib)';
    if (photoReq)  photoReq.textContent = ' (tidak wajib)';
    if (photoNote) photoNote.textContent = isSickness ? 'Sakit: foto dan GPS opsional. Surat dokter mengikuti durasi/kondisi.' : 'Perjadin: foto, GPS, dan attachment bersifat opsional.';
  } else {
    if (gpsRow)    gpsRow.style.opacity   = '1';
    if (photoRow)  photoRow.style.opacity = '1';
    if (gpsReq)    gpsReq.textContent = ' *';
    if (photoReq)  photoReq.textContent = ' *';
    if (photoNote) photoNote.textContent = 'Selfie / foto bukti. Wajib saat submit.';
  }
}

(function(){
  // Init state untuk tipe yang sudah dipilih (preselected)
  var typeInput = document.getElementById('hrlTypeInput');
  var initKey = typeInput ? typeInput.value : 'CUTI';
  updateGpsPhotoVisibility(initKey);
  var noteEl = document.getElementById('typeNote');
  if (noteEl) noteEl.textContent = _hrlTypeNotes[initKey] || '';


  function updateOvertimePreview(){
    var a=document.querySelector('[name="overtime_start_time"]');
    var b=document.querySelector('[name="overtime_end_time"]');
    var brOut=document.getElementById('overtimeBreakPreview');
    var out=document.getElementById('overtimeDurationPreview');
    if(!out||!a||!b||!a.value||!b.value){
      if(brOut) brOut.value='0 menit';
      if(out) out.value='0 jam 0 menit';
      return;
    }
    var x=a.value.split(':').map(Number), y=b.value.split(':').map(Number);
    var m1=x[0]*60+x[1], m2=y[0]*60+y[1];
    if(m2<=m1) m2+=1440;
    var gross=Math.max(0,m2-m1);
    var breakMin=Math.floor(gross/120)*15;
    var net=Math.max(0,gross-breakMin);
    if(brOut) brOut.value=breakMin+' menit';
    out.value=Math.floor(net/60)+' jam '+(net%60)+' menit';
  }
  ['overtime_date','overtime_start_time','overtime_end_time'].forEach(function(n){
    var el=document.querySelector('[name="'+n+'"]'); if(el) el.addEventListener('input',updateOvertimePreview);
  });

  // ── GPS ──
  var btnGeo = document.getElementById('btnGeo');
  var gpsLat = document.getElementById('gps_lat');
  var gpsLng = document.getElementById('gps_lng');
  var gpsAcc = document.getElementById('gps_acc');
  var geoHelp= document.getElementById('geoHelp');

  function setGeoHelp(t, cls){ if(geoHelp){ geoHelp.textContent=t; geoHelp.className='hrl-gps-status '+(cls||''); } }

  if(btnGeo) btnGeo.addEventListener('click', function(){
    if(!window.isSecureContext){
      setGeoHelp('Gagal: GPS hanya berjalan di HTTPS / secure context.', 'err');
      return;
    }

    if (navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({name:'geolocation'}).then(function(p){
        if (p && p.state === 'denied') {
          setGeoHelp('Gagal: izin lokasi browser/site masih DENIED. Klik ikon gembok → Location → Allow, lalu reload.', 'err');
        }
      }).catch(function(){});
    }

    if(!navigator.geolocation){
      setGeoHelp('Browser tidak mendukung Geolocation atau diblokir Permissions-Policy.', 'err');
      return;
    }

    btnGeo.disabled = true;
    btnGeo.textContent = '<?=rmi_icon('calendar')?> Mengambil...';
    setGeoHelp('Mengambil lokasi GPS realtime...', '');

    navigator.geolocation.getCurrentPosition(function(pos){
      gpsLat.value = pos.coords.latitude.toFixed(7);
      gpsLng.value = pos.coords.longitude.toFixed(7);
      gpsAcc.value = Math.round(pos.coords.accuracy || 0);
      setGeoHelp('<?=rmi_icon('tick')?> GPS berhasil — Akurasi ~' + gpsAcc.value + 'm', 'ok');
      btnGeo.disabled = false;
      btnGeo.textContent = '<?=rmi_icon('tick')?> GPS OK';
    }, function(err){
      var msg = err && err.message ? err.message : 'GPS gagal.';
      if (/permissions policy|disabled in this document/i.test(msg)) {
        msg = 'GPS diblokir Permissions-Policy. Pastikan .htaccess/app_init/tower sudah diganti, lalu hard refresh.';
      }
      setGeoHelp('Gagal: ' + msg, 'err');
      btnGeo.disabled = false;
      btnGeo.textContent = '<?=rmi_icon('target')?> Coba Lagi';
    }, { enableHighAccuracy:true, timeout:20000, maximumAge:0 });
  });

  // ── DataTable ──
  $(function(){
    $('#tbl').DataTable({
      pageLength: 15,
      order: [[8,'desc']],
      dom: 'Bfrtip',
      buttons: ['copy','csv','excel','pdf','print'],
      language: { search:'Cari:', lengthMenu:'Tampilkan _MENU_', info:'_START_–_END_ dari _TOTAL_' },
      columnDefs: [{ targets: -1, orderable: false }]
    });
  });
})();
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
