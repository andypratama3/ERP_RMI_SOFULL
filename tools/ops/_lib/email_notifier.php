<?php
declare(strict_types=1);

if (!function_exists('ops_email_alert_enabled')) {
    function ops_email_alert_enabled(): bool
    {
        return trim((string)(getenv('ENABLE_EMAIL_ALERTS') ?: '0')) === '1';
    }
}

if (!function_exists('ops_email_send_sla_breach')) {
    function ops_email_send_sla_breach(array $payload): array
    {
        if (!ops_email_alert_enabled()) {
            return ['ok' => false, 'code' => 'EMAIL_DISABLED'];
        }
        $to = trim((string)(getenv('ALERT_EMAIL_TO') ?: ''));
        if ($to === '') {
            return ['ok' => false, 'code' => 'EMAIL_RECIPIENT_MISSING'];
        }
        $subject = (string)($payload['subject'] ?? '[OPS ALERT]');
        $body = (string)($payload['body'] ?? '');
        $sent = @mail($to, $subject, $body);
        return ['ok' => (bool)$sent, 'code' => $sent ? 'OK' : 'EMAIL_SEND_FAIL'];
    }
}

