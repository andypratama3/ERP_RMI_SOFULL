<?php
declare(strict_types=1);

if (!function_exists('bprid_detect')) {
    /**
     * Detect request_id from inputs and log tails.
     * @param string|null $userProvided User-provided --request-id
     * @param string $error Error text
     * @param string $repro Repro text
     * @param array $logTails [['name'=>string, 'content'=>string], ...]
     * @return array{request_id:string|null, sources:array}
     */
    function bprid_detect(?string $userProvided, string $error, string $repro, array $logTails): array
    {
        if ($userProvided !== null && $userProvided !== '') {
            return ['request_id' => trim($userProvided), 'sources' => ['input']];
        }
        $sources = [];
        $found = bprid_extract_from_text($error);
        if ($found !== null) {
            return ['request_id' => $found, 'sources' => ['error']];
        }
        $found = bprid_extract_from_text($repro);
        if ($found !== null) {
            return ['request_id' => $found, 'sources' => ['repro']];
        }
        foreach ($logTails as $log) {
            $name = (string)($log['name'] ?? '');
            $content = (string)($log['content'] ?? '');
            $found = bprid_extract_from_text($content);
            if ($found !== null) {
                return ['request_id' => $found, 'sources' => ['log:' . $name]];
            }
        }
        return ['request_id' => null, 'sources' => []];
    }
}

if (!function_exists('bprid_extract_from_text')) {
    function bprid_extract_from_text(string $text): ?string
    {
        if (preg_match('/X-Request-Id:\s*([a-zA-Z0-9_\-]+)/i', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/"request_id"\s*:\s*"([^"]+)"/', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/request_id["\']?\s*[:=]\s*["\']?([a-zA-Z0-9_\-]{8,64})/', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i', $text, $m)) {
            return $m[0];
        }
        if (preg_match('/[a-f0-9]{32}/i', $text, $m)) {
            return $m[0];
        }
        return null;
    }
}
