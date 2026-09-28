<?php
declare(strict_types=1);

require_once __DIR__ . '/bug_pack_redact.php';

if (!function_exists('bpm_build')) {
    /**
     * Build manifest structure.
     * @param array $opts run_id, env, issue, included, missing, notes, fix_commands
     */
    function bpm_build(array $opts): array
    {
        $issue = (array)($opts['issue'] ?? []);
        $route = bpr_redact_text((string)($issue['route'] ?? ''));
        $error = bpr_redact_text((string)($issue['error'] ?? ''));
        $repro = bpr_redact_text((string)($issue['repro'] ?? ''));
        $includedList = [];
        foreach ((array)($opts['included'] ?? []) as $e) {
            $item = [
                'path' => $e['rel'] ?? '',
                'bytes' => (int)($e['bytes'] ?? 0),
                'sha256' => $e['sha256'] ?? '',
                'redacted' => (bool)($e['redacted'] ?? true),
            ];
            if (!empty($e['invalid_json'])) $item['invalid_json'] = true;
            if (!empty($e['original_file'])) $item['original_file'] = $e['original_file'];
            if (isset($e['original_line'])) $item['original_line'] = (int)$e['original_line'];
            $includedList[] = $item;
        }
        return [
            'state_version' => 2,
            'generated_at' => date(DateTimeInterface::ATOM),
            'run_id' => (string)($opts['run_id'] ?? ''),
            'profile' => (string)($opts['profile'] ?? 'tools'),
            'env' => (string)($opts['env'] ?? 'unknown'),
            'request_id_detected' => $opts['request_id_detected'] ?? null,
            'request_id_sources' => (array)($opts['request_id_sources'] ?? []),
            'issue' => [
                'route' => $route,
                'error' => $error,
                'repro' => $repro,
            ],
            'included' => $includedList,
            'missing' => (array)($opts['missing'] ?? []),
            'notes' => (array)($opts['notes'] ?? []),
            'fix_commands' => (array)($opts['fix_commands'] ?? []),
            'redaction_summary' => [
                'fail_count' => (int)($opts['redaction_fail_count'] ?? 0),
                'warn_count' => (int)($opts['redaction_warn_count'] ?? 0),
            ],
        ];
    }
}

if (!function_exists('bpm_readme')) {
    function bpm_readme(array $manifest): string
    {
        $issue = (array)($manifest['issue'] ?? []);
        $route = (string)($issue['route'] ?? 'Not specified');
        $error = (string)($issue['error'] ?? 'Not specified');
        $included = (array)($manifest['included'] ?? []);
        $missing = (array)($manifest['missing'] ?? []);
        $fixCommands = (array)($manifest['fix_commands'] ?? []);
        $profile = (string)($manifest['profile'] ?? 'tools');
        $requestId = $manifest['request_id_detected'] ?? null;
        $md = "# Bug Pack – Troubleshooting Evidence\n\n";
        $md .= "## Apa Masalahnya\n\n";
        $md .= "- **Route/Halaman:** " . $route . "\n";
        $md .= "- **Error (redacted):** " . $error . "\n";
        if ($requestId !== null && $requestId !== '') {
            $md .= "- **Request ID:** " . $requestId . "\n";
        }
        $md .= "\n## Apa Isi ZIP Ini\n\n";
        $md .= "- Ringkasan environment (meta/env_summary.json)\n";
        $md .= "- Info git jika ada (meta/git_summary.json)\n";
        $md .= "- Konteks issue (input/issue_context.json)\n";
        $md .= "- State files (health, smoke, contract, exec summary, arch audit) – " . count($included) . " files\n";
        if (!empty($missing)) {
            $md .= "- " . count($missing) . " files missing (lihat manifest.json)\n";
        }
        $md .= "\n## Cara Kirim ke Engineer\n\n";
        $md .= "Kirim file ZIP ini ke tim engineer atau support. Mereka dapat menganalisis tanpa akses langsung ke sistem Anda.\n\n";
        $md .= "## Cara Rerun Pack\n\n";
        $md .= "`php tools/qa/collect_bug_pack.php --run-id=auto --profile=" . $profile . " --route=\"...\" --error=\"...\"`\n\n";
        $md .= "## Apa yang TIDAK Disertakan (Privasi)\n\n";
        $md .= "- Tidak ada dump database\n";
        $md .= "- Tidak ada secret, password, atau API key\n";
        $md .= "- Tidak ada file upload\n";
        $md .= "- Path dan data sensitif sudah di-redact\n\n";
        if (!empty($fixCommands)) {
            $md .= "## Fix Commands (jika data missing)\n\n";
            foreach (array_slice($fixCommands, 0, 5) as $cmd) {
                $md .= "- `" . $cmd . "`\n";
            }
        }
        return $md;
    }
}
