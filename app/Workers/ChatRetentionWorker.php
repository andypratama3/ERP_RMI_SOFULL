<?php
declare(strict_types=1);

namespace App\Workers;

require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../Services/ChatService.php';

use App\Services\ChatService;
use PDO;

final class ChatRetentionWorker
{
    /**
     * @return array{purged_messages:int,purged_attachments:int,mode:string}
     */
    public static function run(PDO $pdo, int $batch = 500): array
    {
        $svc = new ChatService();
        $svc->ensureReady($pdo);
        return $svc->retentionPurgeSoftDeleted($pdo, $batch);
    }
}

