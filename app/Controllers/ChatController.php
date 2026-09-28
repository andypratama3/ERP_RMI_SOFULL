<?php
declare(strict_types=1);

namespace App\Controllers;

final class ChatController
{
    /** @var array<string, mixed>|null */
    private ?array $contextOffer = null;

    private ?string $flashError = null;

    /** @param array<string, mixed>|null $offer */
    public function setContextOffer(?array $offer): void
    {
        $this->contextOffer = $offer;
    }

    public function setFlashError(?string $message): void
    {
        $this->flashError = $message;
    }

    public function render(): void
    {
        require_once __DIR__ . '/../../master/auth.php';
        require_login();
        require_once __DIR__ . '/../../_shared/rmi_layout.php';

        $base = rmi_layout_base_project();
        $csrf = csrf_token();
        $canDelete = function_exists('auth_is_sys_tier') && auth_is_sys_tier();

        $chatContextOffer = $this->contextOffer;
        $chatFlashError = $this->flashError;

        require __DIR__ . '/../../chat/views/index.php';
    }
}
