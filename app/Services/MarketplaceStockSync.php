<?php
declare(strict_types=1);

namespace App\Services;

/**
 * MarketplaceStockSync
 * Outbound stock sync. Default DRY-RUN: no external API call.
 * Output: storage/logs/marketplace_stock_sync_last.json
 */
class MarketplaceStockSync
{
    private \PDO $pdo;
    private bool $dryRun = true;
    private ?string $apiKey = null;
    private ?string $baseUrl = null;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->dryRun = (int)(getenv('MARKETPLACE_STOCK_SYNC_DRY_RUN') ?: '1') === 1;
        $this->apiKey = getenv('MARKETPLACE_STOCK_API_KEY') ?: null;
        $this->baseUrl = getenv('MARKETPLACE_STOCK_BASE_URL') ?: null;
    }

    public function run(): array
    {
        $requestId = 'sync-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $result = [
            'generated_at' => date('c'),
            'ok' => true,
            'dry_run' => $this->dryRun,
            'request_id' => $requestId,
            'items_synced' => 0,
            'items_skipped' => 0,
            'errors' => [],
        ];

        if ($this->dryRun) {
            $result['message'] = 'Dry-run: no external API calls';
            $this->writeLog($result);
            return $result;
        }

        if (!$this->apiKey || !$this->baseUrl) {
            $result['ok'] = false;
            $result['errors'][] = 'API key or base URL not configured';
            $this->writeLog($result);
            return $result;
        }

        try {
            $st = $this->pdo->query("SELECT product_id, sku, stock_qty FROM wqs_stock WHERE stock_qty > 0 LIMIT 100");
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $result['items_skipped']++;
            }
            $result['message'] = 'Sync completed (stub: no external call)';
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['errors'][] = substr($e->getMessage(), 0, 200);
        }

        $this->writeLog($result);
        return $result;
    }

    private function writeLog(array $result): void
    {
        $logDir = function_exists('ts_storage_logs_dir') ? ts_storage_logs_dir() : (__DIR__ . '/../../storage/logs');
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        $path = $logDir . '/marketplace_stock_sync_last.json';
        @file_put_contents($path, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
