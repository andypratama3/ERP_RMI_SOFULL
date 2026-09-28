<?php
declare(strict_types=1);

namespace App\Services;

/**
 * MarketplaceSyncService
 * Map payload -> sales_marketplace_staging. MARKETPLACE_AUTO_CREATE_DO=0 default.
 */
class MarketplaceSyncService
{
    private \PDO $pdo;
    private bool $autoCreateDo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->autoCreateDo = (int)(getenv('MARKETPLACE_AUTO_CREATE_DO') ?: '0') === 1;
    }

    public function syncFromInbox(int $inboxId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM marketplace_orders_inbox WHERE id=? AND processed_at IS NULL LIMIT 1");
        $st->execute([$inboxId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Inbox not found or already processed'];
        }
        $payload = json_decode((string)$row['payload_json'], true);
        if (!is_array($payload)) $payload = [];
        $orderId = trim((string)($payload['order_id'] ?? $payload['external_order_id'] ?? ''));
        if ($orderId === '') $orderId = 'unknown-' . $inboxId;
        $source = (string)($row['source'] ?? 'marketplace');

        $stStaging = $this->pdo->prepare("SELECT id FROM sales_marketplace_staging WHERE external_order_id=? AND source=? LIMIT 1");
        $stStaging->execute([$orderId, $source]);
        if ($stStaging->fetch()) {
            $this->pdo->prepare("UPDATE marketplace_orders_inbox SET processed_at=NOW(), processed_by='sync' WHERE id=?")->execute([$inboxId]);
            return ['ok' => true, 'message' => 'Already staged', 'staging_id' => null];
        }

        $customerName = trim((string)($payload['customer_name'] ?? $payload['customer']['name'] ?? ''));
        $customerPhone = trim((string)($payload['customer_phone'] ?? $payload['customer']['phone'] ?? ''));
        $totalAmount = isset($payload['total_amount']) ? (float)$payload['total_amount'] : null;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO sales_marketplace_staging (inbox_id, external_order_id, source, customer_name, customer_phone, total_amount, status, payload_json) VALUES (?,?,?,?,?,?,'NEW',?)")
                ->execute([$inboxId, $orderId, $source, $customerName ?: null, $customerPhone ?: null, $totalAmount, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
            $stagingId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare("UPDATE marketplace_orders_inbox SET processed_at=NOW(), processed_by='sync' WHERE id=?")->execute([$inboxId]);
            $this->pdo->commit();

            if (function_exists('erp_audit_ensure')) { try { erp_audit_ensure($this->pdo); } catch (\Throwable $e) {} }
            if (function_exists('audit_event')) {
                try {
                    audit_event($this->pdo, 'MARKETPLACE_STAGING_CREATED', 'MARKETPLACE', 'STAGING', (string)$stagingId, 'Staging created from inbox', [
                        'actor_username' => 'system',
                        'inbox_id' => $inboxId,
                        'external_order_id' => $orderId,
                    ]);
                } catch (\Throwable $e) {}
            }

            return ['ok' => true, 'message' => 'Staged', 'staging_id' => $stagingId];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->pdo->prepare("UPDATE marketplace_orders_inbox SET error_msg=?, processed_at=NOW() WHERE id=?")->execute([substr($e->getMessage(), 0, 500), $inboxId]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
