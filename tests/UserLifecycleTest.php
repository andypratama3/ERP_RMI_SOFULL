<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UserLifecycleTest extends TestCase
{
    public function testInactiveUserLoginDenied(): void
    {
        $user = ['status' => 'INACTIVE', 'deleted_at' => null];
        $this->assertSame('inactive', rmi_login_denial_reason($user));
    }

    public function testDeletedUserLoginDenied(): void
    {
        $user = ['status' => 'ACTIVE', 'deleted_at' => '2026-01-01 00:00:00'];
        $this->assertSame('deleted', rmi_login_denial_reason($user));
    }
}

