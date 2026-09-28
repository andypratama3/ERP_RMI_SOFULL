<?php
declare(strict_types=1);

require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_ui_helpers.php';

if (!function_exists('tools_contract_state_read')) {
    function tools_contract_state_read(): array
    {
        $logs = ts_storage_logs_dir();
        $primary = tools_read_state_json($logs . '/contract_check_last.json', ['ok']);
        if ($primary['ok']) {
            return $primary;
        }
        return tools_read_state_json($logs . '/contract_check.last.json', ['ok']);
    }
}

if (!function_exists('tools_alert_evaluate')) {
    function tools_alert_evaluate(): array
    {
        $reasons = [];
        $level = 'HEALTHY';
        $readiness = tools_read_state_json(ts_storage_logs_dir() . '/readiness_report_last.json', ['score']);
        $smoke = tools_read_state_json(ts_storage_logs_dir() . '/smoke_http_last.json', ['fail']);
        $contract = tools_contract_state_read();
        $backup = ts_latest_backup_meta();

        $score = (int)($readiness['data']['score'] ?? 0);
        $smokeFail = (int)($smoke['data']['fail'] ?? 0);
        $contractOk = (bool)($contract['data']['ok'] ?? false);
        $ageHours = (int)($backup['age_hours'] ?? 9999);

        if (!$readiness['ok']) {
            $reasons[] = 'Readiness state belum valid';
            $level = 'ATTENTION';
        } elseif ($score < 80) {
            $reasons[] = 'Readiness score < 80';
            $level = 'CRITICAL';
        } elseif ($score < 100) {
            $reasons[] = 'Readiness score belum 100';
            if ($level !== 'CRITICAL') {
                $level = 'ATTENTION';
            }
        }

        if (!$smoke['ok']) {
            $reasons[] = 'Smoke state belum valid';
            $level = $level === 'HEALTHY' ? 'ATTENTION' : $level;
        } elseif ($smokeFail > 0) {
            $reasons[] = 'Smoke fail > 0';
            $level = 'CRITICAL';
        }

        if (!$contract['ok']) {
            $reasons[] = 'Contract check state belum valid';
            $level = $level === 'HEALTHY' ? 'ATTENTION' : $level;
        } elseif (!$contractOk) {
            $reasons[] = 'Contract check tidak OK';
            $level = 'CRITICAL';
        }

        if ($ageHours > 72) {
            $reasons[] = 'Backup terakhir > 72 jam';
            $level = 'CRITICAL';
        } elseif ($ageHours > 24) {
            $reasons[] = 'Backup terakhir > 24 jam';
            if ($level === 'HEALTHY') {
                $level = 'ATTENTION';
            }
        }

        if (!$reasons) {
            $reasons[] = 'Semua checks sehat';
        }
        return [
            'level' => $level,
            'reasons' => array_map(static fn(string $r): string => tools_mask_sensitive($r), $reasons),
            'checked_at' => date(DateTimeInterface::ATOM),
            'metrics' => [
                'readiness_score' => $score,
                'smoke_fail' => $smokeFail,
                'contract_ok' => $contractOk,
                'backup_age_hours' => $ageHours,
            ],
        ];
    }
}

if (!function_exists('tools_alert_maybe_email')) {
    function tools_alert_maybe_email(array $alert): void
    {
        $enabled = trim((string)(getenv('ENABLE_EMAIL_ALERTS') ?: '0')) === '1';
        $to = trim((string)(getenv('ALERT_EMAIL_TO') ?: ''));
        if (!$enabled || $to === '') {
            return;
        }
        if (strtoupper((string)($alert['level'] ?? 'HEALTHY')) === 'HEALTHY') {
            return;
        }

        $statePath = ts_storage_logs_dir() . '/alerts_last.json';
        $state = ts_read_json($statePath);
        $throttleHours = (int)(getenv('ALERT_EMAIL_THROTTLE_HOURS') ?: '6');
        $throttleHours = max(1, $throttleHours);
        $fingerprint = sha1(json_encode([$alert['level'], $alert['reasons']], JSON_UNESCAPED_SLASHES) ?: 'x');
        $now = time();
        $lastAt = (int)($state['last_sent_at_epoch'] ?? 0);
        $lastFp = (string)($state['last_fingerprint'] ?? '');
        $throttled = $lastFp === $fingerprint && ($now - $lastAt) < ($throttleHours * 3600);
        if ($throttled) {
            return;
        }

        $subject = '[ERP Tools Alert] ' . strtoupper((string)$alert['level']);
        $body = tools_mask_sensitive(
            "Alert level: " . (string)$alert['level'] . "\n" .
            "Checked at: " . tools_fmt_ts((string)($alert['checked_at'] ?? '')) . "\n" .
            "Reasons: " . implode('; ', (array)($alert['reasons'] ?? [])) . "\n"
        );
        @mail($to, $subject, $body);
        ts_write_json($statePath, [
            'state_version' => 1,
            'last_sent_at_epoch' => $now,
            'last_sent_at' => date(DateTimeInterface::ATOM),
            'last_fingerprint' => $fingerprint,
            'last_level' => (string)$alert['level'],
        ]);
    }
}
