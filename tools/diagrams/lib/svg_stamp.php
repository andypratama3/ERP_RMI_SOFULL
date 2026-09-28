<?php
declare(strict_types=1);
/**
 * Inject RMI logo stamp + metadata into SVG.
 * Watermark bottom-right: logo, generated_at, request_id.
 */
if (!function_exists('diagrams_stamp_svg')) {
    function diagrams_stamp_svg(
        string $svg,
        string $diagramName,
        string $requestId,
        string $generatedAt,
        string $logoPath,
        float $opacity = 0.2
    ): string {
        $logoData = @file_get_contents($logoPath);
        $logoB64 = $logoData ? base64_encode($logoData) : '';
        $logoDataUri = $logoB64 ? 'data:image/png;base64,' . $logoB64 : '';

        $title = "RMI | ERP_RMI_SOFULL | " . htmlspecialchars($diagramName, ENT_XML1, 'UTF-8');
        $headerText = $title;

        $stamp = '';
        if ($logoDataUri) {
            $stamp .= '<g opacity="' . $opacity . '" style="pointer-events:none">';
            $stamp .= '<image x="75%" y="70%" width="120" height="50" href="' . $logoDataUri . '" preserveAspectRatio="xMidYMid meet"/>';
            $stamp .= '<text x="75%" y="88%" font-size="9" fill="#666" font-family="Helvetica,sans-serif">Rizqullah Mediska Indonesia</text>';
            $stamp .= '<text x="75%" y="92%" font-size="8" fill="#888">Generated: ' . htmlspecialchars($generatedAt, ENT_XML1, 'UTF-8') . '</text>';
            $stamp .= '<text x="75%" y="96%" font-size="8" fill="#888">Request: ' . htmlspecialchars($requestId, ENT_XML1, 'UTF-8') . '</text>';
            $stamp .= '</g>';
        }

        if (!preg_match('/<title>/', $svg)) {
            $svg = preg_replace('/<svg([^>]*)>/', '<svg$1><title>' . $title . '</title>', $svg, 1);
        }
        if (!preg_match('/<text[^>]*RMI \| ERP/', $svg)) {
            $svg = preg_replace('/<svg([^>]*)>/', '<svg$1><text x="10" y="24" font-size="12" font-weight="bold" fill="#333" font-family="Helvetica,sans-serif">' . $headerText . '</text>', $svg, 1);
        }
        $svg = str_replace('</svg>', $stamp . '</svg>', $svg);

        return $svg;
    }
}
