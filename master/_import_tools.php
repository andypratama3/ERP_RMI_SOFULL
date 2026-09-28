<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/erp_audit.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require_login();
}

if (!function_exists('imp_h')) {
    function imp_h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('imp_parse_csv_assoc')) {
    /**
     * @return array{headers:array<int,string>,rows:array<int,array<string,string>>,errors:array<int,string>}
     */
    function imp_parse_csv_assoc(string $tmpPath, array $requiredHeaders): array
    {
        $errors = [];
        $rows = [];
        $headers = [];
        $fh = @fopen($tmpPath, 'r');
        if (!$fh) {
            return ['headers' => [], 'rows' => [], 'errors' => ['CSV tidak dapat dibaca.']];
        }
        $headerRow = fgetcsv($fh, 0, ',', '"', '\\');
        if (!is_array($headerRow) || !$headerRow) {
            fclose($fh);
            return ['headers' => [], 'rows' => [], 'errors' => ['Header CSV kosong.']];
        }
        $headers = array_map(static fn($h) => strtolower(trim((string)$h)), $headerRow);
        $missing = array_values(array_diff($requiredHeaders, $headers));
        if ($missing) {
            fclose($fh);
            return ['headers' => $headers, 'rows' => [], 'errors' => ['Header wajib tidak lengkap: ' . implode(', ', $missing)]];
        }
        while (($line = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (!$line) continue;
            $row = [];
            foreach ($headers as $i => $name) {
                $row[$name] = trim((string)($line[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($fh);
        if (!$rows) $errors[] = 'Data CSV kosong.';
        return ['headers' => $headers, 'rows' => $rows, 'errors' => $errors];
    }
}

if (!function_exists('imp_create_run')) {
    function imp_create_run(PDO $pdo, string $module, string $fileName, int $rowsTotal): int
    {
        $st = $pdo->prepare("INSERT INTO master_import_runs (module, file_name, status, rows_total, created_by, created_at) VALUES (?, ?, 'PREVIEW', ?, ?, NOW())");
        $st->execute([$module, $fileName, $rowsTotal, (int)($_SESSION['user_id'] ?? 0) ?: null]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('imp_finish_run')) {
    function imp_finish_run(PDO $pdo, int $runId, string $status, int $ok, int $fail, string $notes = ''): void
    {
        $st = $pdo->prepare("UPDATE master_import_runs SET status=?, rows_success=?, rows_failed=?, finished_at=NOW(), notes=? WHERE id=?");
        $st->execute([$status, $ok, $fail, $notes !== '' ? $notes : null, $runId]);
    }
}

if (!function_exists('imp_log_row_error')) {
    function imp_log_row_error(PDO $pdo, int $runId, int $rowNo, string $externalKey, string $error, array $payload): void
    {
        $st = $pdo->prepare("INSERT INTO master_import_run_errors (run_id, row_no, external_key, error_message, row_payload_json, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $st->execute([$runId, $rowNo, $externalKey !== '' ? $externalKey : null, $error, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }
}

if (!function_exists('imp_table_has_column')) {
    function imp_table_has_column(PDO $pdo, string $table, string $column): bool
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    }
}

