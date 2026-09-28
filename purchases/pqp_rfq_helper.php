<?php
/**
 * purchases/pqp_rfq_helper.php
 * Helper: email notifikasi, currency rate, audit log untuk RFQ.
 */
declare(strict_types=1);

if (!function_exists('pqp_rfq_email_enabled')) {
    function pqp_rfq_email_enabled(): bool {
        return trim((string)(getenv('ENABLE_EMAIL_ALERTS') ?: getenv('ENABLE_RFQ_EMAIL') ?: '0')) === '1';
    }
}

if (!function_exists('pqp_rfq_send_email')) {
    /** Kirim email ke satu alamat. Return true jika berhasil. */
    function pqp_rfq_send_email(string $to, string $subject, string $body): bool {
        if (!pqp_rfq_email_enabled()) return false;
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        return (bool)@mail($to, $subject, $body, 'Content-Type: text/plain; charset=UTF-8');
    }
}

if (!function_exists('pqp_rfq_notify_manufacturers_open')) {
    /**
     * Saat RFQ status open → kirim email ke manufacturer portal users yang punya email.
     */
    function pqp_rfq_notify_manufacturers_open(PDO $pdo, array $rfq): int {
        $sent = 0;
        if (!pqp_rfq_email_enabled()) return $sent;
        $st = $pdo->query("
            SELECT DISTINCT u.email, u.full_name, u.manufacture_code
            FROM manufacturer_portal_users u
            WHERE u.status='active' AND u.email IS NOT NULL AND u.email != ''
        ");
        $baseUrl = rmi_env('APP_URL') ?: (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $portalUrl = rtrim($baseUrl, '/') . '/manufacturer_portal/rfq.php';
        $subject = '[RMI RFQ] ' . ($rfq['rfq_code'] ?? '') . ' — ' . ($rfq['title'] ?? '');
        $body = "RFQ baru tersedia:\n\n";
        $body .= "Kode: " . ($rfq['rfq_code'] ?? '') . "\n";
        $body .= "Judul: " . ($rfq['title'] ?? '') . "\n";
        $body .= "Deadline: " . ($rfq['deadline'] ?? '') . "\n\n";
        $body .= "Silakan login ke Manufacturer Portal dan submit quotation:\n" . $portalUrl . "\n";
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (pqp_rfq_send_email($row['email'], $subject, $body)) $sent++;
        }
        return $sent;
    }
}

if (!function_exists('pqp_rfq_get_pqp_emails')) {
    /** Daftar email PQP untuk notifikasi RFQ. Prioritas: system_config → PQP_RFQ_EMAIL env → ALERT_EMAIL_TO env. */
    function pqp_rfq_get_pqp_emails(PDO $pdo): array {
        try {
            $st = $pdo->prepare("SELECT config_value FROM system_config WHERE config_group='RFQ' AND config_key='PQP_EMAIL' AND is_active=1 LIMIT 1");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v !== false && trim((string)$v) !== '') {
                $emails = array_filter(array_map('trim', explode(',', (string)$v)));
                $emails = array_filter($emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
                if (!empty($emails)) return $emails;
            }
        } catch (Throwable $e) {}
        $env = trim((string)(getenv('PQP_RFQ_EMAIL') ?: getenv('ALERT_EMAIL_TO') ?: ''));
        if ($env !== '') {
            $emails = array_filter(array_map('trim', explode(',', $env)));
            return array_values(array_filter($emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        }
        return [];
    }
}

if (!function_exists('pqp_rfq_notify_pqp_submitted')) {
    /**
     * Saat manufacturer submit quotation → notifikasi ke PQP (multi-email).
     */
    function pqp_rfq_notify_pqp_submitted(PDO $pdo, array $rfq, string $manufactureCode, string $manufactureName = ''): bool {
        if (!pqp_rfq_email_enabled()) return false;
        $emails = pqp_rfq_get_pqp_emails($pdo);
        if (empty($emails)) return false;
        $baseUrl = rmi_env('APP_URL') ?: (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $rfqUrl = rtrim($baseUrl, '/') . '/purchases/pqp_rfq.php?id=' . (int)($rfq['id'] ?? 0);
        $subject = '[RMI RFQ] Quotation baru: ' . ($rfq['rfq_code'] ?? '') . ' dari ' . $manufactureCode;
        $body = "Manufacturer " . $manufactureCode . ($manufactureName ? " ({$manufactureName})" : '') . " telah submit quotation untuk RFQ " . ($rfq['rfq_code'] ?? '') . ".\n\n";
        $body .= "Lihat perbandingan: " . $rfqUrl . "\n";
        $sent = 0;
        foreach ($emails as $to) {
            if (pqp_rfq_send_email($to, $subject, $body)) $sent++;
        }
        return $sent > 0;
    }
}

if (!function_exists('pqp_rfq_currency_rate')) {
    /** Rate ke USD. Fallback default jika tidak ada di system_config. */
    function pqp_rfq_currency_rate(PDO $pdo, string $currency): float {
        $currency = strtoupper(trim($currency));
        if ($currency === 'USD') return 1.0;
        $st = $pdo->prepare("SELECT config_value FROM system_config WHERE config_group='RFQ_CURRENCY' AND config_key=? AND is_active=1 LIMIT 1");
        $st->execute(['rate_' . $currency]);
        $v = $st->fetchColumn();
        if ($v !== false && is_numeric($v)) return (float)$v;
        $defaults = ['CNY' => 0.14, 'IDR' => 0.000063, 'EUR' => 1.08];
        return (float)($defaults[$currency] ?? 1.0);
    }
}

if (!function_exists('pqp_rfq_to_usd')) {
    function pqp_rfq_to_usd(PDO $pdo, float $amount, string $currency): float {
        return $amount * pqp_rfq_currency_rate($pdo, $currency);
    }
}

if (!function_exists('pqp_rfq_audit')) {
    /**
     * Audit log RFQ. Mendukung context ERP (SESSION) dan Manufacturer Portal (mportal_*).
     */
    function pqp_rfq_audit(PDO $pdo, string $module, string $recordTable, string $action, ?int $recordId, ?string $recordCode, string $description, array $details = []): void {
        if (!function_exists('master_audit_ensure_table')) {
            require_once __DIR__ . '/../master/_audit_master.php';
        }
        $u = [
            'user_id'   => $_SESSION['user_id'] ?? $_SESSION['mportal_user_id'] ?? null,
            'username'  => $_SESSION['username'] ?? $_SESSION['mportal_username'] ?? ($_SESSION['user']['username'] ?? ''),
            'role'      => $_SESSION['role'] ?? (isset($_SESSION['mportal_user_id']) ? 'manufacturer_portal' : ''),
            'level'     => $_SESSION['level'] ?? '',
        ];
        if ($u['username'] === '' && isset($_SESSION['mportal_manufacture_code'])) {
            $u['username'] = 'portal_' . ($_SESSION['mportal_username'] ?? $_SESSION['mportal_manufacture_code'] ?? 'unknown');
        }
        master_audit_ensure_table($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO system_audit_logs
            (module, action, record_table, record_id, record_code, description, details,
             user_id, username, role, level, ip, user_agent, created_at)
            VALUES
            (:module, :action, :rt, :rid, :rcode, :descr, :details,
             :uid, :uname, :role, :level, :ip, :ua, NOW())
        ");
        $stmt->execute([
            ':module'  => $module,
            ':action'  => $action,
            ':rt'      => $recordTable,
            ':rid'     => $recordId,
            ':rcode'   => $recordCode,
            ':descr'   => $description,
            ':details' => !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ':uid'     => $u['user_id'],
            ':uname'   => substr((string)$u['username'], 0, 100),
            ':role'    => substr((string)$u['role'], 0, 50),
            ':level'   => substr((string)$u['level'], 0, 50),
            ':ip'      => substr(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]), 0, 45),
            ':ua'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
        ]);
    }
}
