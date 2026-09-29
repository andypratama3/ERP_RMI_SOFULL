<?php
/**
 * _shared/actor_stamp.php
 * SATU-SATUNYA sumber blok penanggung jawab ("Disiapkan/Dikirim/Diterima").
 *
 * Relasi WAJIB utuh, tanpa menebak:
 *   <username pelaku>
 *     -> master_system_login.username
 *     -> master_system_login.holder_employee_code
 *     -> master_employees.employee_code / employee_name
 *
 * Format standar (semua halaman WAJIB sama):
 *     username                <- AKUN pelaku, baris utama (mis. StaffWQS_TGR)
 *     Nama Karyawan (KODE)    <- bila relasi employee utuh
 *     dd-mm-yyyy HH:ii:ss     <- waktu aksi
 *     Tercatat otomatis oleh ERP  <- di atas garis tanda tangan
 *
 * Akun TIDAK lagi dicetak sebagai baris "Akun: ..." di paling bawah: posisi
 * lama menaruhnya di bawah garis tanda tangan (.erp-actor-line punya
 * border-top) sehingga terlihat lepas dari identitas pelaku.
 *
 * Kalau relasi putus: TAMPILKAN APA ADANYA (nama akun / username), JANGAN
 * mengarang nama dan JANGAN menulis kode departemen ('WQS'/'SCM') sebagai orang.
 *
 * Pemakaian:
 *   $info = rmi_actor_info($pdo, $username);
 *   echo rmi_actor_stamp($pdo, $username, $timestamp, ['title' => 'Disiapkan WQS']);
 *   echo rmi_actor_stamp_text($pdo, $username, $timestamp, 35); // CF monospace
 */

require_once __DIR__ . '/rmi_icons.php';

if (!function_exists('rmi_actor_is_dept_code')) {
    /** Kode departemen/role yang pernah ternilai 'WQS'/'SCM' pada kolom *_by lama. */
    function rmi_actor_is_dept_code(string $v): bool
    {
        return in_array(strtoupper(trim($v)),
            ['WQS', 'SCM', 'ACT', 'FIN', 'CRM', 'HRL', 'ITC', 'PR', 'PO', 'DO'], true);
    }
}

if (!function_exists('rmi_actor_info')) {
    /**
     * @return array{label:string,account:string,account_name:string,employee_code:string}
     */
    function rmi_actor_info(PDO $pdo, string $username): array
    {
        static $cache = [];
        $u = trim($username);
        $empty = ['label' => '', 'account' => '', 'account_name' => '', 'employee_code' => ''];
        if ($u === '') return $empty;
        if (rmi_actor_is_dept_code($u)) return $empty;   // bukan orang
        if (isset($cache[$u])) return $cache[$u];

        $info = ['label' => '', 'account' => $u, 'account_name' => '', 'employee_code' => ''];
        try {
            $st = $pdo->prepare("SELECT full_name, holder_employee_code FROM master_system_login WHERE username=? LIMIT 1");
            $st->execute([$u]);
            $login = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $code = trim((string) ($login['holder_employee_code'] ?? ''));
            $fn   = trim((string) ($login['full_name'] ?? ''));
            if ($fn !== '') $info['account_name'] = $fn;
            if ($code !== '') {
                $st2 = $pdo->prepare("SELECT employee_name FROM master_employees WHERE employee_code=? LIMIT 1");
                $st2->execute([$code]);
                $nm = trim((string) ($st2->fetchColumn() ?: ''));
                if ($nm !== '') {
                    $info['employee_code'] = $code;
                    $info['label'] = "{$nm} ({$code})";
                    return $cache[$u] = $info;
                }
                // Kode ada tapi employee tidak ada: laporkan kode, jangan karang nama.
                $info['employee_code'] = $code;
            }
            if ($fn !== '') $info['label'] = $fn;   // jatuh ke nama akun — sah, bukan hardcode
        } catch (Throwable $e) {
            // fail-soft
        }
        if ($info['label'] === '') $info['label'] = $u;
        return $cache[$u] = $info;
    }
}

if (!function_exists('rmi_actor_label')) {
    function rmi_actor_label(PDO $pdo, string $username): string
    {
        return rmi_actor_info($pdo, $username)['label'];
    }
}

if (!function_exists('rmi_actor_stamp_datetime')) {
    function rmi_actor_stamp_datetime(string $ts): string
    {
        $ts = trim($ts);
        if ($ts === '' || $ts === '0000-00-00 00:00:00') return '';
        $t = strtotime($ts);
        return $t === false ? '' : date('d-m-Y H:i:s', $t);
    }
}

if (!function_exists('rmi_actor_stamp')) {
    /**
     * Blok tanda tangan HTML standar.
     *
     * @param array{title?:string,placeholder?:string,show_account?:bool,note?:string} $opt
     */
    function rmi_actor_stamp(PDO $pdo, string $username, string $ts = '', array $opt = []): string
    {
        $title    = (string) ($opt['title'] ?? 'Disiapkan');
        $titleFb  = (string) ($opt['title_fallback'] ?? $title);
        $ph       = (string) ($opt['placeholder'] ?? '-');
        $showAcc  = ($opt['show_account'] ?? true) !== false;
        $note     = (string) ($opt['note'] ?? 'Tercatat otomatis oleh ERP');
        $info     = rmi_actor_info($pdo, $username);
        $datetime = rmi_actor_stamp_datetime($ts);
        $h        = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        // Judul memakai akun aktual; fallback ke judul baku saat belum ada aksi.
        if ($info['label'] === '') $title = $titleFb;

        $out  = '<div class="sign-box"><div class="sign-title">' . $h($title) . '</div>';
        if ($info['label'] === '') {
            // Belum ada aksi: tetap cetak label, TIDAK pernah mengarang pelaku.
            $out .= '<div class="erp-actor-stamp"><div class="erp-actor-name">' . $h($ph) . '</div>';
        } else {
            // Baris utama = AKUN pelaku (mis. StaffWQS_TGR). Ini yang dicari
            // pembaca print, jadi naik ke atas tanpa prefix "Akun:".
            // Dulu akun diletak paling bawah — di BAWAH garis tanda tangan
            // (.erp-actor-line punya border-top), jadi terlihat lepas dari
            // identitas pelaku.
            $main = ($showAcc && $info['account'] !== '') ? $info['account'] : $info['label'];
            $out .= '<div class="erp-actor-stamp">'
                 . '<div class="erp-actor-name">' . $h($main) . '</div>';
            // Nama employee tetap dicetak di bawah, TIDAK pernah dikarang.
            if ($info['label'] !== '' && $info['label'] !== $main) {
                $out .= '<div class="erp-actor-meta">' . $h($info['label']) . '</div>';
            }
            if ($datetime !== '') {
                $out .= '<div class="erp-actor-meta">' . $h($datetime) . '</div>';
            }
            if ($note !== '') {
                $out .= '<div class="erp-actor-line">' . $h($note) . '</div>';
            }
        }
        return $out . '</div></div>';
    }
}

if (!function_exists('rmi_actor_stamp_text')) {
    /**
     * Blok tanda tangan monospace (print CF / printer thermal). Selalu 4 baris
     * agar alignment kolom tidak bergeser.
     */
    function rmi_actor_stamp_text(PDO $pdo, string $username, string $ts = '', int $width = 35): string
    {
        $info     = rmi_actor_info($pdo, $username);
        $datetime = rmi_actor_stamp_datetime($ts);
        $w        = max(20, $width);
        $pad      = static function (string $s) use ($w): string {
            $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
            return $s . str_repeat(' ', max(0, $w - $len));
        };
        $name = $info['label'] !== '' ? $info['label'] : '-';
        // Sama seperti rmi_actor_stamp(): akun jadi baris utama, tanpa prefix.
        $l1 = $pad($info['account'] !== '' ? $info['account'] : $name);
        $l2 = $pad($info['label'] !== '' && $info['label'] !== $info['account'] ? $info['label'] : '');
        $l3 = $pad($datetime);
        $l4 = $pad('Tercatat otomatis oleh ERP');
        return $l1 . "\n" . $l2 . $l3 . $l4;
    }
}
