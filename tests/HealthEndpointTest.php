<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function testHealthBaseStructureAlwaysContainsRequiredKeys(): void
    {
        $base = rmi_health_base_structure();
        $this->assertArrayHasKey('db', $base);
        $this->assertArrayHasKey('storage', $base);
        $this->assertArrayHasKey('cron', $base);
        $this->assertArrayHasKey('worker_queue', $base);
        $this->assertArrayHasKey('latest_backup', $base);
    }

    public function testHealthMergeGracefulWhenDbFails(): void
    {
        $base = rmi_health_base_structure();
        $payload = rmi_health_merge($base, [
            'db' => ['ok' => false, 'latency_ms' => null],
        ]);
        $this->assertFalse($payload['db']['ok']);
        $this->assertArrayHasKey('storage', $payload);
        $this->assertArrayHasKey('cron', $payload);
        $this->assertArrayHasKey('worker_queue', $payload);
    }
}

