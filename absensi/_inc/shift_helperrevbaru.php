<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403); exit('Forbidden');
}

// ── Auto-create tabel shift jika belum ada ───────────────────────────────────
function absensi_shift_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS absensi_shifts (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        shift_name      VARCHAR(80)  NOT NULL,
        checkin_time    TIME         NOT NULL,
        checkout_time   TIME         NOT NULL,
        late_tolerance_min    INT    NOT NULL DEFAULT 0,
        overtime_threshold_min INT   NOT NULL DEFAULT 30,
        is_overnight    TINYINT(1)   NOT NULL DEFAULT 0,
        is_active       TINYINT(1)   NOT NULL DEFAULT 1,
        note            VARCHAR(255) NULL,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME     NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS absensi_user_shifts (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        username       VARCHAR(80)  NOT NULL,
        shift_id       INT          NOT NULL,
        effective_date DATE         NOT NULL,
        end_date       DATE         NULL,
        note           VARCHAR(255) NULL,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_date (username, effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed shift default jika tabel masih kosong
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM absensi_shifts")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO absensi_shifts
            (id,shift_name,checkin_time,checkout_time,late_tolerance_min,overtime_threshold_min,is_overnight,is_active,note)
            VALUES
            (1,'Shift Pagi','08:00:00','17:00:00',30,30,0,1,'Jam kerja normal kantor'),
            (2,'Shift Siang','13:00:00','22:00:00',30,30,0,1,'Shift siang/sore')");
        $pdo->exec("INSERT IGNORE INTO absensi_settings (k,v,updated_at) VALUES ('default_shift_id','1',NOW())");
    }
}

// ── Ambil semua shift ────────────────────────────────────────────────────────
function absensi_shifts_all(PDO $pdo, bool $activeOnly = false): array {
    $sql = "SELECT * FROM absensi_shifts" . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY checkin_time, id";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// ── Ambil satu shift by ID ───────────────────────────────────────────────────
function absensi_shift_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM absensi_shifts WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// ── Resolusi shift untuk user pada tanggal tertentu ─────────────────────────
// Urutan prioritas: user_shifts → default_shift_id di settings → shift id=1
function absensi_resolve_shift(PDO $pdo, string $username, string $date): ?array {
    // 1) Cek absensi_user_shifts — assignment spesifik user
    $st = $pdo->prepare("
        SELECT s.*
        FROM absensi_user_shifts us
        JOIN absensi_shifts s ON s.id = us.shift_id
        WHERE us.username = ?
          AND us.effective_date <= ?
          AND (us.end_date IS NULL OR us.end_date >= ?)
          AND s.is_active = 1
        ORDER BY us.effective_date DESC
        LIMIT 1
    ");
    $st->execute([$username, $date, $date]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;

    // 2) Pakai default_shift_id dari settings
    $defId = (int)(absensi_setting($pdo, 'default_shift_id', '1') ?? '1');
    if ($defId > 0) {
        $shift = absensi_shift_by_id($pdo, $defId);
        if ($shift && $shift['is_active']) return $shift;
    }

    // 3) Fallback: shift pertama yang aktif
    $st2 = $pdo->query("SELECT * FROM absensi_shifts WHERE is_active=1 ORDER BY checkin_time LIMIT 1");
    $r2  = $st2->fetch(PDO::FETCH_ASSOC);
    return $r2 ?: null;
}

// ── Format TIME dari DB (HH:MM:SS) → HH:MM ──────────────────────────────────
function absensi_shift_fmt(string $t): string {
    return substr($t, 0, 5);
}

// ── Hitung status check-in berdasarkan shift ─────────────────────────────────
// Return: 'on_time' | 'late' | 'unknown'
function absensi_shift_checkin_status(string $cinTime, array $shift): string {
    // $cinTime format HH:MM (dari created_at)
    if ($cinTime === '') return 'unknown';
    $std = absensi_shift_fmt($shift['checkin_time']);
    $tol = (int)($shift['late_tolerance_min'] ?? 0);
    if ($tol > 0) {
        $ts = strtotime('2000-01-01 ' . $std . ':00');
        if ($ts !== false) $std = date('H:i', $ts + $tol * 60);
    }
    return ($cinTime > $std) ? 'late' : 'on_time';
}

// ── Hitung durasi lembur (menit) ─────────────────────────────────────────────
// $coutTime: HH:MM, $shift: row dari absensi_shifts
// Return: menit lembur (0 jika tidak ada)
function absensi_shift_overtime_min(string $coutTime, array $shift): int {
    if ($coutTime === '') return 0;

    $shiftOut = absensi_shift_fmt($shift['checkout_time']);
    $threshold = (int)($shift['overtime_threshold_min'] ?? 30);

    $tsShift = strtotime('2000-01-01 ' . $shiftOut . ':00');
    $tsCout  = strtotime('2000-01-01 ' . $coutTime  . ':00');

    if (!$tsShift || !$tsCout) return 0;

    // Untuk shift overnight: jika checkout < checkout_time, berarti menyebrang hari
    if ((bool)($shift['is_overnight'] ?? false) && $tsCout < $tsShift) {
        $tsCout += 86400; // tambah 1 hari
    }

    $diffMin = (int)(($tsCout - $tsShift) / 60);
    return ($diffMin >= $threshold) ? $diffMin : 0;
}

// ── Format menit lembur ke string (misal: 1j 30m) ───────────────────────────
function absensi_fmt_overtime(int $minutes): string {
    if ($minutes <= 0) return '-';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h > 0 && $m > 0) return "{$h}j {$m}m";
    if ($h > 0) return "{$h}j";
    return "{$m}m";
}

// ── Hitung durasi kerja aktual (menit) ───────────────────────────────────────
function absensi_work_duration_min(string $cinTime, string $coutTime, array $shift): int {
    if ($cinTime === '' || $coutTime === '') return 0;
    $tsIn  = strtotime('2000-01-01 ' . $cinTime  . ':00');
    $tsOut = strtotime('2000-01-01 ' . $coutTime . ':00');
    if (!$tsIn || !$tsOut) return 0;
    if ((bool)($shift['is_overnight'] ?? false) && $tsOut < $tsIn) {
        $tsOut += 86400;
    }
    $diff = (int)(($tsOut - $tsIn) / 60);
    return max(0, $diff);
}
