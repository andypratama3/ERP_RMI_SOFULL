<?php
/**
 * _shared/rmi_icons.php
 * SATU-SATUNYA sumber icon ERP. TIDAK ADA emoji hardcode di modul.
 *
 * Pemakaian:
 *   rmi_icon('box')            -> inline SVG (default, untuk HTML)
 *   rmi_icon('box', 'is-lg')   -> SVG + class tambahan
 *   rmi_icon_text('box')       -> glyph teks, HANYA untuk placeholder/option/title attr
 *                                  atau konteks non-HTML (mis. export, email, log).
 *
 * Aturan: jangan pernah memanggil rmi_icon() di dalam htmlspecialchars()/h()/rmi_h()
 * untuk output HTML — SVG harus lolos mentah. Untuk teks biasa tetap escape.
 */

if (!function_exists('rmi_icon_paths')) {
    function rmi_icon_paths(string $name): array {
        static $map = [
            'check'     => ['M20 6 9 17l-5-5'],
            'tick'      => ['M20 6 9 17l-5-5'],
            'cross'     => ['M18 6 6 18', 'M6 6l12 12'],
            'x'         => ['M18 6 6 18', 'M6 6l12 12'],
            'warn'      => ['M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z', 'M12 9v4', 'M12 17h.01'],
            'question'  => ['M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3', 'M12 17h.01', 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z'],
            'box'       => ['m7.5 4.27 9 5.15', 'M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z', 'm3.3 7 8.7 5 8.7-5', 'M12 22V12'],
            'chart'     => ['M3 3v16a2 2 0 0 0 2 2h16', 'M7 16v-5', 'M12 16V8', 'M17 16v-3'],
            'money'     => ['M12 2v20', 'M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'],
            'clipboard' => ['M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2', 'M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z', 'M9 12h6', 'M9 16h6'],
            'refresh'   => ['M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8', 'M21 3v5h-5', 'M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16', 'M8 16H3v5'],
            'books'     => ['M4 19.5A2.5 2.5 0 0 1 6.5 17H20', 'M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z', 'M10 7h6'],
            'memo'      => ['M15.5 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.5Z', 'M15 3v6h6', 'M8 13h8', 'M8 17h5'],
            'office'    => ['M3 21h18', 'M5 21V7l7-4 7 4v14', 'M9 9h.01', 'M9 13h.01', 'M9 17h.01', 'M15 9h2', 'M15 13h2', 'M15 17h2'],
            'calendar'  => ['M8 2v4', 'M16 2v4', 'M3 10h18', 'M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z'],
            'tower'     => ['M12 2 4 7v10l8 5 8-5V7Z', 'M12 22V12', 'm4 7 8 5 8-5'],
            'user'      => ['M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2', 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z'],
            'users'     => ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M22 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'],
            'search'    => ['m21 21-4.34-4.34', 'M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z'],
            'cart'      => ['M8 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z', 'M19 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z', 'M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12'],
            'target'    => ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z', 'M12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12Z', 'M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'],
            'inbox'     => ['M22 12h-6l-2 3h-4l-2-3H2', 'M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z'],
            'outbox'    => ['M22 12h-6l-2 3h-4l-2-3H2', 'M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z', 'M12 16V4', 'm8 8 4-4 4 4'],
            'zap'       => ['M4 14h7l-1 8 10-12h-7l1-8Z'],
            'home'      => ['m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z', 'M9 22V12h6v10'],
            'doc'       => ['M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z', 'M14 2v5h6', 'M9 13h6', 'M9 17h4'],
            'gear'      => ['M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', 'M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.31.4.55.72.68H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z'],
            'trend'     => ['M16 7h6v6', 'm22 7-8.5 8.5-5-5L2 17'],
            'receipt'   => ['M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z', 'M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8', 'M12 17.5v-11'],
            'print'     => ['M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2', 'M6 9V3h12v6', 'M6 18h12v3H6Z'],
            'moon'      => ['M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z'],
            'sun'       => ['M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z', 'M12 1v2', 'M12 21v2', 'M4.22 4.22l1.42 1.42', 'M18.36 18.36l1.42 1.42', 'M1 12h2', 'M21 12h2', 'M4.22 19.78l1.42-1.42', 'M18.36 5.64l1.42-1.42'],
            'truck'     => ['M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2', 'M15 18H9', 'M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14', 'M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z', 'M17 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'],
            'lock'      => ['M5 11h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z', 'M7 11V7a5 5 0 0 1 10 0v4'],
            'pin'       => ['M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z', 'M12 10a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z'],
            'clock'     => ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z', 'M12 6v6l4 2'],
            'info'      => ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z', 'M12 16v-4', 'M12 8h.01'],
            'star'      => ['m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01Z'],
            'trash'     => ['M3 6h18', 'M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2', 'M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', 'M10 11v6', 'M14 11v6'],
            'plus'      => ['M12 5v14', 'M5 12h14'],
            'minus'     => ['M5 12h14'],
            'edit'      => ['M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7', 'M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z'],
            'filter'    => ['M22 3H2l8 9.46V19l4 2v-8.54Z'],
            'download'  => ['M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'm7 10 5 5 5-5', 'M12 15V3'],
            'upload'    => ['M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'm17 8-5-5-5 5', 'M12 3v12'],
            'external'  => ['M15 3h6v6', 'M10 14 21 3', 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6'],
            'save'      => ['M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z', 'M17 21v-8H7v8', 'M7 3v5h8'],
            'eye'       => ['M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0Z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
            'play'      => ['m5 3 14 9-14 9Z'],
            'send'      => ['m22 2-7 20-4-9-9-4Z', 'M22 2 11 13'],
            'phone'     => ['M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z'],
            'mail'      => ['M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z', 'm22 6-10 7L2 6'],
            'key'       => ['m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4', 'm21 2-9.6 9.6', 'M7.5 15.5a5 5 0 1 1 0-7.07 5 5 0 0 1 0 7.07Z', 'm10.5 10.5 4 4'],
            'shield'    => ['M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1Z'],
            'database'  => ['M12 8c4.97 0 9-1.34 9-3s-4.03-3-9-3-9 1.34-9 3 4.03 3 9 3Z', 'M3 5v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5', 'M3 12c0 1.66 4.03 3 9 3s9-1.34 9-3'],
            'menu'      => ['M4 5h16', 'M4 12h16', 'M4 19h16'],
            'logout'    => ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'm16 17 5-5-5-5', 'M21 12H9'],
        ];
        return $map[$name] ?? null;
    }
}

if (!function_exists('rmi_icon')) {
    /**
     * Inline SVG icon. Aman di HTML, HANYA JANGAN di-escape.
     */
    function rmi_icon(string $name, string $extra_class = ''): string {
        $paths = rmi_icon_paths($name);
        if ($paths === null) {
            return '';
        }
        $cls = 'rmi-i rmi-i-' . preg_replace('/[^a-z0-9_-]/i', '', $name);
        if ($extra_class !== '') {
            $cls .= ' ' . $extra_class;
        }
        $d = '';
        foreach ($paths as $p) {
            $d .= '<path d="' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '"/>';
        }
        return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 24" fill="none" '
             . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
             . 'aria-hidden="true" focusable="false">' . $d . '</svg>';
    }
}

if (!function_exists('rmi_icon_text')) {
    /**
     * Glyph teks untuk konteks NON-HTML: atribut placeholder/title/alt,
     * isi <option>, export CSV/PDF, email, atau log. JANGAN pakai untuk body HTML.
     */
    function rmi_icon_text(string $name): string {
        static $glyph = [
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
            'moon' => "\u{1F319}", 'sun' => "\u{2600}\u{FE0F}", 'truck' => "\u{1F69A}",
            'lock' => "\u{1F512}", 'pin' => "\u{1F4CD}", 'clock' => "\u{23F1}",
            'info' => "\u{2139}\u{FE0F}", 'star' => "\u{2B50}", 'trash' => "\u{1F5D1}",
            'plus' => "\u{2795}", 'minus' => "\u{2796}", 'edit' => "\u{270F}",
            'filter' => "\u{1F50D}", 'download' => "\u{1F4E5}", 'upload' => "\u{1F4E4}",
            'external' => "\u{1F517}", 'save' => "\u{1F4BE}", 'eye' => "\u{1F441}",
            'play' => "\u{25B6}", 'send' => "\u{1F4E4}", 'phone' => "\u{1F4DE}",
            'mail' => "\u{2709}", 'key' => "\u{1F511}", 'shield' => "\u{1F6E1}",
            'database' => "\u{1F5C4}", 'menu' => "\u{2630}", 'logout' => "\u{1F6BB}",
        ];
        return $glyph[$name] ?? '';
    }
}
