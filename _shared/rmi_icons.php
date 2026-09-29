<?php
// _shared/rmi_icons.php — SATU-SATUNYA sumber icon/emoji ERP.
// Jangan hardcode emoji di file modul; pakai rmi_icon('nama').
// Helper mengembalikan karakter yang sama seperti sebelumnya (aman).
if (!function_exists('rmi_icon')) {
    function rmi_icon(string $name): string {
        static $map = [
            'check' => "\u{2705}", 'tick' => "\u{2713}", 'cross' => "\u{274C}",
            'x' => "\u{2717}", 'warn' => "\u{26A0}", 'question' => "\u{2753}",
            'box' => "\u{1F4E6}", 'chart' => "\u{1F4CA}", 'money' => "\u{1F4B0}",
            'clipboard' => "\u{1F4CB}", 'refresh' => "\u{1F504}", 'books' => "\u{1F4DA}",
            'memo' => "\u{1F4DD}", 'office' => "\u{1F3E2}", 'calendar' => "\u{1F4C5}",
            'tower' => "\u{1F5FC}", 'user' => "\u{1F464}", 'users' => "\u{1F465}",
            'search' => "\u{1F50D}", 'cart' => "\u{1F6D2}", 'target' => "\u{1F3AF}",
            'inbox' => "\u{1F4E5}", 'outbox' => "\u{1F4E4}", 'zap' => "\u{26A1}",
            'home' => "\u{1F3E0}", 'doc' => "\u{1F4C4}", 'gear' => "\u{2699}",
            'trend' => "\u{1F4C8}", 'receipt' => "\u{1F9FE}", 'print' => "\u{1F5A8}",
            'moon' => "\u{1F319}", 'sun' => "\u{2600}\u{FE0F}",
        ];
        return $map[$name] ?? '';
    }
}
