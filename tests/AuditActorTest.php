<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuditActorTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testActorUsesUsernameWhenPresent(): void
    {
        $_SESSION['username'] = 'qa_actor';
        $_SESSION['level'] = 'ADMIN';
        $this->assertSame('qa_actor', current_actor_username());
    }

    public function testActorFallsBackToSystemWhenNoSessionUsername(): void
    {
        $_SESSION['level'] = 'ADMIN';
        $this->assertSame('SYSTEM', current_actor_username());
    }
}

