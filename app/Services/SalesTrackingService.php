<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class SalesTrackingService
{
    /**
     * @return array<string,mixed>
     */
    public function syncByDoId(PDO $pdo, int $doId, bool $allowStatusTransition = false): array
    {
        $row = $this->findDoById($pdo, $doId);
        if (!$row) {
            throw new \RuntimeException('DO not found.');
        }
        return $this->syncRow($pdo, $row, $allowStatusTransition);
    }

    /**
     * @return array{total:int,ok:int,failed:int,items:array<int,array<string,mixed>>}
     */
    public function syncActive(PDO $pdo, int $limit = 50, bool $allowStatusTransition = false): array
    {
        $limit = max(1, min(500, $limit));
        $st = $pdo->prepare(
            "SELECT id, do_code, tracking_code, status, carrier_provider, carrier_tracking_no,
                    carrier_courier_code, tracking_public_token, fallback_live_location_url
             FROM sales_do
             WHERE status IN ('ready_scm','on_delivery')
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        $ok = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $items[] = $this->syncRow($pdo, $row, $allowStatusTransition);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $items[] = [
                    'do_id' => (int)($row['id'] ?? 0),
                    'do_code' => (string)($row['do_code'] ?? ''),
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['total' => count($rows), 'ok' => $ok, 'failed' => $failed, 'items' => $items];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findPublicByToken(PDO $pdo, string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[a-zA-Z0-9\-_]{32,120}$/', $token)) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT d.id, d.do_code, d.tracking_code, d.status, d.carrier_provider, d.carrier_tracking_no,
                    d.tracking_last_sync_at, d.tracking_last_status, d.fallback_live_location_url, c.customers_name
             FROM sales_do d
             LEFT JOIN master_customers c ON c.customers_code=d.customers_code
             WHERE d.tracking_public_token=?
             LIMIT 1"
        );
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $ev = $pdo->prepare(
            "SELECT event_time, provider_status, internal_status, description, location
             FROM sales_do_tracking_events
             WHERE do_id=?
             ORDER BY event_time DESC, id DESC
             LIMIT 30"
        );
        $ev->execute([(int)$row['id']]);
        $row['events'] = $ev->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $row['customers_name_masked'] = $this->maskCustomerName((string)($row['customers_name'] ?? 'Customer'));
        return $row;
    }

    public function ensurePublicToken(PDO $pdo, int $doId): string
    {
        $st = $pdo->prepare("SELECT tracking_public_token FROM sales_do WHERE id=? LIMIT 1");
        $st->execute([$doId]);
        $token = trim((string)$st->fetchColumn());
        if ($token !== '' && preg_match('/^[a-zA-Z0-9\-_]{32,120}$/', $token)) {
            return $token;
        }
        for ($i = 0; $i < 5; $i++) {
            $candidate = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
            try {
                $pdo->prepare("UPDATE sales_do SET tracking_public_token=? WHERE id=?")->execute([$candidate, $doId]);
                return $candidate;
            } catch (\Throwable $e) {
                // retry on collision
            }
        }
        throw new \RuntimeException('Failed to generate public token.');
    }

    public function publicTrackingUrl(PDO $pdo, string $token): string
    {
        $base = trim($this->systemConfig($pdo, 'PUBLIC_BASE_URL', ''));
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = $scheme . '://' . $host;
            $pathPrefix = $this->detectBasePath();
            if ($pathPrefix !== '') {
                $base = rtrim($base, '/') . '/' . ltrim($pathPrefix, '/');
            }
        }
        return rtrim($base, '/') . '/sales/tracking_public.php?t=' . rawurlencode($token);
    }

    private function detectBasePath(): string
    {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script === '') return '';
        $script = str_replace('\\', '/', $script);
        $known = '(master|stock|dashboards|sales|hrl|api|tools)';
        if (preg_match('~^(.*?)/' . $known . '(?:/|$)~i', $script, $m)) {
            $base = rtrim($m[1], '/');
            return ($base === '/' ? '' : $base);
        }
        return '';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function syncRow(PDO $pdo, array $row, bool $allowStatusTransition): array
    {
        $doId = (int)$row['id'];
        $provider = strtoupper(trim((string)($row['carrier_provider'] ?: $this->systemConfig($pdo, 'PROVIDER_DEFAULT', 'BITESHIP'))));
        if ($provider === '') $provider = 'BITESHIP';

        $trackingNo = trim((string)($row['carrier_tracking_no'] ?? ''));
        if ($trackingNo === '') {
            $trackingNo = trim((string)($row['tracking_code'] ?? ''));
        }
        $token = trim((string)($row['tracking_public_token'] ?? ''));
        if ($token === '') {
            $token = $this->ensurePublicToken($pdo, $doId);
        }
        if ($trackingNo === '') {
            $this->updateSnapshot($pdo, $doId, $provider, 'fallback_only', json_encode(['reason' => 'tracking_no_missing'], JSON_UNESCAPED_SLASHES), null);
            return [
                'do_id' => $doId,
                'do_code' => (string)($row['do_code'] ?? ''),
                'ok' => true,
                'used_fallback' => true,
                'status_transition' => 'none',
                'public_url' => $this->publicTrackingUrl($pdo, $token),
            ];
        }

        $providerPayload = null;
        $providerError = null;
        if ($provider === 'BITESHIP') {
            try {
                $providerPayload = $this->fetchBiteship($pdo, $trackingNo, (string)($row['carrier_courier_code'] ?? ''));
            } catch (\Throwable $e) {
                $providerError = $e->getMessage();
            }
        } else {
            $providerError = 'Unsupported provider.';
        }

        if (!$providerPayload) {
            $this->updateSnapshot($pdo, $doId, $provider, 'fallback_only', json_encode(['error' => $providerError ?: 'provider_failed'], JSON_UNESCAPED_SLASHES), null);
            return [
                'do_id' => $doId,
                'do_code' => (string)($row['do_code'] ?? ''),
                'ok' => true,
                'used_fallback' => true,
                'provider_error' => $providerError,
                'status_transition' => 'none',
                'public_url' => $this->publicTrackingUrl($pdo, $token),
            ];
        }

        $normalized = $this->normalizePayload($providerPayload);
        $guard = $this->guardStatusTransition((string)($row['status'] ?? ''), (string)$normalized['mapped_status']);

        $pdo->beginTransaction();
        try {
            $this->insertEvents($pdo, $doId, $normalized['events']);
            $statusToApply = ($allowStatusTransition && $guard['allow']) ? (string)$normalized['mapped_status'] : null;
            $this->updateSnapshot(
                $pdo,
                $doId,
                $provider,
                (string)$normalized['provider_status'],
                json_encode(['provider_status' => $normalized['provider_status'], 'mapped_status' => $normalized['mapped_status']], JSON_UNESCAPED_SLASHES),
                $statusToApply
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'do_id' => $doId,
            'do_code' => (string)($row['do_code'] ?? ''),
            'ok' => true,
            'provider_status' => (string)$normalized['provider_status'],
            'mapped_status' => (string)$normalized['mapped_status'],
            'status_transition' => (string)$guard['reason'],
            'status_applied' => ($allowStatusTransition && $guard['allow']) ? (string)$normalized['mapped_status'] : (string)($row['status'] ?? ''),
            'events_count' => count($normalized['events']),
            'public_url' => $this->publicTrackingUrl($pdo, $token),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findDoById(PDO $pdo, int $doId): ?array
    {
        if ($doId <= 0) return null;
        $st = $pdo->prepare(
            "SELECT id, do_code, tracking_code, status, carrier_provider, carrier_tracking_no, carrier_courier_code,
                    tracking_public_token, fallback_live_location_url
             FROM sales_do WHERE id=? LIMIT 1"
        );
        $st->execute([$doId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array{provider_status:string,mapped_status:string,events:array<int,array<string,mixed>>}
     */
    private function normalizePayload(array $payload): array
    {
        $providerStatus = (string)($payload['status'] ?? ($payload['tracking']['status'] ?? ($payload['data']['status'] ?? 'unknown')));
        $eventsRaw = [];
        if (isset($payload['history']) && is_array($payload['history'])) $eventsRaw = $payload['history'];
        elseif (isset($payload['events']) && is_array($payload['events'])) $eventsRaw = $payload['events'];
        elseif (isset($payload['tracking']['history']) && is_array($payload['tracking']['history'])) $eventsRaw = $payload['tracking']['history'];

        $events = [];
        foreach ($eventsRaw as $ev) {
            if (!is_array($ev)) continue;
            $evStatus = (string)($ev['status'] ?? $providerStatus);
            $events[] = [
                'event_time' => $this->toDateTime((string)($ev['updated_at'] ?? ($ev['timestamp'] ?? ($ev['time'] ?? '')))),
                'provider_status' => $evStatus,
                'internal_status' => $this->mapProviderStatus($evStatus),
                'description' => (string)($ev['note'] ?? ($ev['description'] ?? 'Tracking update')),
                'location' => (string)($ev['location'] ?? ''),
                'raw_json' => json_encode($ev, JSON_UNESCAPED_SLASHES),
            ];
        }
        if (!$events) {
            $events[] = [
                'event_time' => date('Y-m-d H:i:s'),
                'provider_status' => $providerStatus,
                'internal_status' => $this->mapProviderStatus($providerStatus),
                'description' => 'Tracking sync',
                'location' => '',
                'raw_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ];
        }
        return ['provider_status' => $providerStatus, 'mapped_status' => $this->mapProviderStatus($providerStatus), 'events' => $events];
    }

    /**
     * @return array{allow:bool,reason:string}
     */
    private function guardStatusTransition(string $current, string $mapped): array
    {
        $cur = strtolower(trim($current));
        $next = strtolower(trim($mapped));
        if ($next === '' || $cur === $next) return ['allow' => false, 'reason' => 'no_change'];
        if (in_array($cur, ['wait_payment', 'paid', 'closed', 'cancelled', 'fin_done', 'paid_done'], true)) return ['allow' => false, 'reason' => 'blocked_final_state'];
        if ($next === 'ready_scm') return ['allow' => false, 'reason' => 'no_downgrade_to_ready'];
        if ($next === 'on_delivery' && in_array($cur, ['ready_scm', 'on_delivery'], true)) return ['allow' => true, 'reason' => 'allow_to_on_delivery'];
        if ($next === 'delivered' && in_array($cur, ['ready_scm', 'on_delivery', 'delivered'], true)) return ['allow' => true, 'reason' => 'allow_to_delivered'];
        return ['allow' => false, 'reason' => 'guard_default_block'];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     */
    private function insertEvents(PDO $pdo, int $doId, array $events): void
    {
        $ins = $pdo->prepare(
            "INSERT INTO sales_do_tracking_events (do_id, event_time, provider_status, internal_status, description, location, raw_json)
             VALUES (?,?,?,?,?,?,?)"
        );
        $chk = $pdo->prepare(
            "SELECT id FROM sales_do_tracking_events
             WHERE do_id=? AND event_time=? AND provider_status=? AND description=?
             LIMIT 1"
        );
        foreach ($events as $ev) {
            $eventTime = (string)($ev['event_time'] ?? date('Y-m-d H:i:s'));
            $providerStatus = (string)($ev['provider_status'] ?? '');
            $description = mb_substr((string)($ev['description'] ?? 'Tracking update'), 0, 255);
            $chk->execute([$doId, $eventTime, $providerStatus, $description]);
            if ($chk->fetchColumn()) continue;
            $ins->execute([
                $doId,
                $eventTime,
                $providerStatus,
                (string)($ev['internal_status'] ?? ''),
                $description,
                mb_substr((string)($ev['location'] ?? ''), 0, 255),
                (string)($ev['raw_json'] ?? null),
            ]);
        }
    }

    private function updateSnapshot(PDO $pdo, int $doId, string $provider, string $lastStatus, string $payloadJson, ?string $statusToApply): void
    {
        $sets = [
            "carrier_provider=?",
            "tracking_last_sync_at=NOW()",
            "tracking_last_status=?",
            "tracking_last_payload_json=?",
            "last_updated_by='SCM_TRACKING'",
            "last_updated_at=NOW()",
        ];
        $params = [$provider, $lastStatus, mb_substr($payloadJson, 0, 60000)];
        if ($statusToApply !== null && $statusToApply !== '') {
            $sets[] = "status=?";
            $params[] = $statusToApply;
            if ($statusToApply === 'on_delivery') $sets[] = "scm_on_delivery_at=COALESCE(scm_on_delivery_at,NOW())";
            if ($statusToApply === 'delivered') $sets[] = "scm_delivered_at=COALESCE(scm_delivered_at,NOW())";
        }
        $params[] = $doId;
        $pdo->prepare("UPDATE sales_do SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchBiteship(PDO $pdo, string $trackingNo, string $courierCode): array
    {
        $apiKey = trim((string)(getenv('BITESHIP_API_KEY') ?: ''));
        if ($apiKey === '') {
            throw new \RuntimeException('BITESHIP_API_KEY not configured.');
        }
        $tpl = trim($this->systemConfig($pdo, 'BITESHIP_TRACKING_ENDPOINT', ''));
        if ($tpl === '') {
            $tpl = (string)(getenv('BITESHIP_TRACKING_ENDPOINT') ?: 'https://api.biteship.com/v1/trackings/{tracking_no}/couriers/{courier_code}');
        }
        $endpoint = str_replace(
            ['{tracking_no}', '{courier_code}'],
            [rawurlencode($trackingNo), rawurlencode($courierCode !== '' ? $courierCode : 'jnt')],
            $tpl
        );
        $timeout = max(3, (int)$this->systemConfig($pdo, 'BITESHIP_TIMEOUT_SECONDS', '8'));
        $lastErr = 'unknown';
        for ($i = 0; $i < 2; $i++) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: ' . $apiKey],
            ]);
            $res = curl_exec($ch);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($errno !== 0) {
                $lastErr = 'curl_error:' . $err;
                continue;
            }
            if ($code < 200 || $code >= 300) {
                $lastErr = 'http_status:' . $code;
                continue;
            }
            $arr = json_decode((string)$res, true);
            if (!is_array($arr)) {
                $lastErr = 'invalid_json';
                continue;
            }
            return $arr;
        }
        throw new \RuntimeException('Biteship request failed: ' . $lastErr);
    }

    private function mapProviderStatus(string $providerStatus): string
    {
        $s = strtolower(trim($providerStatus));
        foreach (['delivered', 'completed', 'signed', 'received'] as $k) if (str_contains($s, $k)) return 'delivered';
        foreach (['on_delivery', 'out_for_delivery', 'transit', 'in_transit', 'courier', 'picked', 'delivery'] as $k) if (str_contains($s, $k)) return 'on_delivery';
        return 'ready_scm';
    }

    private function toDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return date('Y-m-d H:i:s');
        $ts = strtotime($raw);
        return $ts === false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $ts);
    }

    private function systemConfig(PDO $pdo, string $key, string $default): string
    {
        try {
            $st = $pdo->prepare(
                "SELECT config_value
                 FROM system_config
                 WHERE config_group='SALES_TRACKING' AND config_key=? AND is_active=1
                 ORDER BY office_code IS NOT NULL ASC, id DESC
                 LIMIT 1"
            );
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v !== false ? (string)$v : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function maskCustomerName(string $name): string
    {
        $name = trim($name);
        if ($name === '') return 'Customer';
        if (mb_strlen($name) <= 2) return mb_substr($name, 0, 1) . '*';
        return mb_substr($name, 0, 2) . str_repeat('*', 4);
    }
}

__halt_compiler();

<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

final class SalesTrackingService
{
    public function ensureSchema(PDO $pdo): void
    {
        $this->ensureColumn($pdo, 'sales_do', 'carrier_provider', "carrier_provider VARCHAR(30) NULL AFTER tracking_code");
        $this->ensureColumn($pdo, 'sales_do', 'carrier_tracking_no', "carrier_tracking_no VARCHAR(100) NULL AFTER carrier_provider");
        $this->ensureColumn($pdo, 'sales_do', 'carrier_courier_code', "carrier_courier_code VARCHAR(50) NULL AFTER carrier_tracking_no");
        $this->ensureColumn($pdo, 'sales_do', 'tracking_public_token', "tracking_public_token VARCHAR(80) NULL AFTER carrier_courier_code");
        $this->ensureColumn($pdo, 'sales_do', 'tracking_last_sync_at', "tracking_last_sync_at DATETIME NULL AFTER tracking_public_token");
        $this->ensureColumn($pdo, 'sales_do', 'tracking_last_status', "tracking_last_status VARCHAR(100) NULL AFTER tracking_last_sync_at");
        $this->ensureColumn($pdo, 'sales_do', 'tracking_last_payload_json', "tracking_last_payload_json LONGTEXT NULL AFTER tracking_last_status");
        $this->ensureColumn($pdo, 'sales_do', 'fallback_live_location_url', "fallback_live_location_url VARCHAR(1000) NULL AFTER tracking_last_payload_json");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_do_tracking_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                do_id INT NOT NULL,
                event_time DATETIME NOT NULL,
                provider_status VARCHAR(100) NULL,
                internal_status VARCHAR(50) NULL,
                description VARCHAR(255) NULL,
                location VARCHAR(255) NULL,
                raw_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sdt_do_time (do_id, event_time),
                KEY idx_sdt_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function ensurePublicToken(PDO $pdo, int $doId): string
    {
        $st = $pdo->prepare("SELECT tracking_public_token FROM sales_do WHERE id=? LIMIT 1");
        $st->execute([$doId]);
        $token = (string)$st->fetchColumn();
        if ($token !== '') {
            return $token;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        $pdo->prepare("UPDATE sales_do SET tracking_public_token=? WHERE id=?")->execute([$token, $doId]);
        return $token;
    }

    public function buildPublicTrackingUrl(PDO $pdo, string $token): string
    {
        return $this->publicTrackingUrl($pdo, $token);
    }

    public function sanitizeFallbackUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (!preg_match('#^https://#i', $url)) return '';
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /**
     * @return array{ok:bool,message:string,status?:string,events_count?:int,token?:string,public_url?:string}
     */
    public function syncDo(PDO $pdo, int $doId, bool $allowTransition = false): array
    {
        $this->ensureSchema($pdo);
        $st = $pdo->prepare("SELECT * FROM sales_do WHERE id=? LIMIT 1");
        $st->execute([$doId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'message' => 'DO tidak ditemukan'];

        $provider = strtoupper(trim((string)($row['carrier_provider'] ?? '')));
        if ($provider === '') $provider = strtoupper($this->configGet($pdo, 'PROVIDER_DEFAULT', 'BITESHIP'));
        if ($provider !== 'BITESHIP') return ['ok' => false, 'message' => 'Provider belum didukung: ' . $provider];

        $trackingNo = trim((string)($row['carrier_tracking_no'] ?? ''));
        if ($trackingNo === '') $trackingNo = trim((string)($row['tracking_code'] ?? ''));
        if ($trackingNo === '') return ['ok' => false, 'message' => 'Nomor tracking kosong'];

        $endpoint = trim((string)$this->configGet($pdo, 'BITESHIP_TRACKING_ENDPOINT', ''));
        if ($endpoint === '' && function_exists('rmi_env')) $endpoint = trim((string)rmi_env('BITESHIP_TRACKING_ENDPOINT', ''));
        $apiKey = function_exists('rmi_env') ? trim((string)rmi_env('BITESHIP_API_KEY', '')) : '';
        if ($apiKey === '') $apiKey = trim((string)$this->configGet($pdo, 'BITESHIP_API_KEY', ''));
        if ($endpoint === '' || $apiKey === '') {
            return ['ok' => false, 'message' => 'Konfigurasi Biteship belum lengkap'];
        }

        $courierCode = trim((string)($row['carrier_courier_code'] ?? ''));
        $url = str_replace(
            ['{tracking_no}', '{courier_code}'],
            [rawurlencode($trackingNo), rawurlencode($courierCode)],
            $endpoint
        );

        $http = $this->httpGetJson($url, [
            'Authorization: ' . $apiKey,
            'Content-Type: application/json',
        ]);
        if (!$http['ok']) {
            $this->touchSyncMeta($pdo, $doId, 'SYNC_ERROR', ['error' => $http['message']]);
            return ['ok' => false, 'message' => 'Gagal sync provider: ' . $http['message']];
        }

        $payload = $http['data'];
        $status = $this->extractProviderStatus($payload);
        $events = $this->extractEvents($payload);
        $mapped = $this->mapInternalStatus($status);
        $nextStatus = $allowTransition
            ? $this->decideNextStatus((string)($row['status'] ?? ''), $mapped)
            : (string)($row['status'] ?? 'ready_scm');

        try {
            $pdo->beginTransaction();
            $token = $this->ensurePublicToken($pdo, $doId);
            $publicUrl = $this->buildPublicTrackingUrl($pdo, $token);

            $pdo->prepare("
                UPDATE sales_do
                SET carrier_provider=?,
                    carrier_tracking_no=?,
                    tracking_last_sync_at=NOW(),
                    tracking_last_status=?,
                    tracking_last_payload_json=?,
                    tracking_public_token=?,
                    status=?
                WHERE id=?
            ")->execute([
                $provider,
                $trackingNo,
                $status,
                json_encode($payload, JSON_UNESCAPED_SLASHES),
                $token,
                $nextStatus,
                $doId,
            ]);

            foreach ($events as $evt) {
                $pdo->prepare("
                    INSERT INTO sales_do_tracking_events
                        (do_id, event_time, provider_status, internal_status, description, location, raw_json)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $doId,
                    $evt['event_time'],
                    $evt['provider_status'],
                    $this->mapInternalStatus((string)$evt['provider_status']),
                    $evt['description'],
                    $evt['location'],
                    $evt['raw_json'],
                ]);
            }
            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'Tracking tersinkron',
                'status' => $status,
                'events_count' => count($events),
                'token' => $token,
                'public_url' => $publicUrl,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => 'Sync gagal: ' . $e->getMessage()];
        }
    }

    /**
     * @return array{total:int,ok:int,failed:int,rows:array<int,array<string,mixed>>}
     */
    public function syncActive(PDO $pdo, int $limit = 50, bool $allowTransition = false): array
    {
        $this->ensureSchema($pdo);
        $limit = max(1, min(500, $limit));
        $rows = $pdo->query("
            SELECT id
            FROM sales_do
            WHERE status IN ('ready_scm','on_delivery')
              AND carrier_tracking_no IS NOT NULL
              AND TRIM(carrier_tracking_no) <> ''
            ORDER BY id DESC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = ['total' => count($rows), 'ok' => 0, 'failed' => 0, 'rows' => []];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) continue;
            $ret = $this->syncDo($pdo, $id, $allowTransition);
            $out['rows'][] = ['do_id' => $id, 'ok' => $ret['ok'], 'message' => $ret['message']];
            if ($ret['ok']) $out['ok']++; else $out['failed']++;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function syncByDoId(PDO $pdo, int $doId, bool $allowTransition = false): array
    {
        $ret = $this->syncDo($pdo, $doId, $allowTransition);
        return array_merge(['do_id' => $doId], $ret);
    }

    private function ensureColumn(PDO $pdo, string $table, string $column, string $ddl): void
    {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $st->execute([$column]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        } catch (Throwable $e) {
        }
    }

    private function configGet(PDO $pdo, string $key, string $default): string
    {
        try {
            $st = $pdo->prepare("
                SELECT config_value
                FROM system_config
                WHERE config_group='SALES_TRACKING' AND config_key=? AND is_active=1
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            if ($v === false || trim((string)$v) === '') return $default;
            return trim((string)$v);
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function touchSyncMeta(PDO $pdo, int $doId, string $status, array $payload): void
    {
        try {
            $pdo->prepare("
                UPDATE sales_do
                SET tracking_last_sync_at=NOW(),
                    tracking_last_status=?,
                    tracking_last_payload_json=?
                WHERE id=?
            ")->execute([$status, json_encode($payload, JSON_UNESCAPED_SLASHES), $doId]);
        } catch (Throwable $e) {
        }
    }

    /**
     * @return array{ok:bool,message:string,data:array<string,mixed>}
     */
    private function httpGetJson(string $url, array $headers): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'message' => 'cURL tidak tersedia', 'data' => []];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'message' => ($err ?: 'HTTP request gagal'), 'data' => []];
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) return ['ok' => false, 'message' => 'Response provider bukan JSON valid', 'data' => []];
        if ($code < 200 || $code >= 300) return ['ok' => false, 'message' => 'HTTP ' . $code, 'data' => $json];
        return ['ok' => true, 'message' => 'ok', 'data' => $json];
    }

    private function extractProviderStatus(array $payload): string
    {
        foreach ([
            $payload['status'] ?? null,
            $payload['data']['status'] ?? null,
            $payload['data']['delivery_status'] ?? null,
            $payload['tracking']['status'] ?? null,
        ] as $v) {
            $s = strtoupper(trim((string)$v));
            if ($s !== '') return $s;
        }
        return 'UNKNOWN';
    }

    /**
     * @return array<int,array{event_time:string,provider_status:string,description:string,location:string,raw_json:string}>
     */
    private function extractEvents(array $payload): array
    {
        $list = [];
        if (isset($payload['history']) && is_array($payload['history'])) $list = $payload['history'];
        elseif (isset($payload['events']) && is_array($payload['events'])) $list = $payload['events'];
        elseif (isset($payload['data']['history']) && is_array($payload['data']['history'])) $list = $payload['data']['history'];
        elseif (isset($payload['data']['events']) && is_array($payload['data']['events'])) $list = $payload['data']['events'];

        $rows = [];
        foreach ($list as $evt) {
            if (!is_array($evt)) continue;
            $rawTime = (string)($evt['updated_at'] ?? $evt['event_time'] ?? $evt['timestamp'] ?? $evt['time'] ?? '');
            $rows[] = [
                'event_time' => $this->normalizeDateTime($rawTime),
                'provider_status' => strtoupper(trim((string)($evt['status'] ?? $evt['state'] ?? $evt['description'] ?? 'UNKNOWN'))),
                'description' => mb_substr(trim((string)($evt['description'] ?? $evt['note'] ?? $evt['message'] ?? '')), 0, 255),
                'location' => mb_substr(trim((string)($evt['location'] ?? $evt['city'] ?? '')), 0, 255),
                'raw_json' => (string)json_encode($evt, JSON_UNESCAPED_SLASHES),
            ];
        }
        if (!$rows) {
            $rows[] = [
                'event_time' => date('Y-m-d H:i:s'),
                'provider_status' => $this->extractProviderStatus($payload),
                'description' => 'Latest provider status',
                'location' => '',
                'raw_json' => (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
            ];
        }
        return $rows;
    }

    private function normalizeDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return date('Y-m-d H:i:s');
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return date('Y-m-d H:i:s');
        }
    }

    private function mapInternalStatus(string $providerStatus): string
    {
        $s = strtoupper(trim($providerStatus));
        if ($s === '') return '';
        if (str_contains($s, 'DELIVER')) return 'delivered';
        if (str_contains($s, 'TRANSIT') || str_contains($s, 'DELIVERY') || str_contains($s, 'SHIPPED')) return 'on_delivery';
        return '';
    }

    private function decideNextStatus(string $currentStatus, string $mapped): string
    {
        $cur = strtolower(trim($currentStatus));
        $next = strtolower(trim($mapped));
        if ($next === '') return $cur !== '' ? $cur : 'ready_scm';
        if (in_array($cur, ['delivered','wait_payment','paid','closed'], true)) return $cur;
        if ($cur === 'ready_scm' && $next === 'on_delivery') return 'on_delivery';
        if (in_array($cur, ['ready_scm','on_delivery'], true) && $next === 'delivered') return 'delivered';
        return $cur !== '' ? $cur : $next;
    }
}
