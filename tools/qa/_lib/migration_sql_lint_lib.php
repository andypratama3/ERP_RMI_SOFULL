<?php
declare(strict_types=1);

if (!function_exists('msl_root')) {
    function msl_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('msl_collect_sql_files')) {
    /** @return string[] */
    function msl_collect_sql_files(string $dir): array
    {
        $root = msl_root();
        $full = $dir[0] === '/' ? $dir : $root . '/' . $dir;
        if (!is_dir($full)) return [];
        $files = [];
        $it = new DirectoryIterator($full);
        foreach ($it as $fi) {
            if (!$fi->isFile()) continue;
            if (strtolower($fi->getExtension()) !== 'sql') continue;
            $files[] = $fi->getPathname();
        }
        sort($files);
        return $files;
    }
}

if (!function_exists('msl_lint_file')) {
    /**
     * @return array<array{rule:string,severity:string,line:int?,message:string}>
     */
    function msl_lint_file(string $path, string $root, bool $strict): array
    {
        $issues = [];
        $relPath = str_replace($root . '/', '', $path);
        $basename = basename($path, '.sql');
        $content = @file_get_contents($path);
        if ($content === false) return [['rule' => 'READ_FAIL', 'severity' => 'FAIL', 'line' => null, 'message' => 'Cannot read file']];
        $lines = explode("\n", $content);

        $headerLines = array_slice($lines, 0, 10);
        $headerText = implode("\n", $headerLines);
        $hasMigrationId = stripos($headerText, 'migration_id') !== false;
        $hasFilename = stripos($headerText, $basename) !== false;
        $hasCommentHeader = false;
        foreach ($headerLines as $l) {
            $t = trim($l);
            if ($t === '') continue;
            if (str_starts_with($t, '--') || str_starts_with($t, '/*')) {
                $hasCommentHeader = true;
                break;
            }
            break;
        }
        if (!$hasCommentHeader || (!$hasMigrationId && !$hasFilename)) {
            $issues[] = ['rule' => 'HEADER', 'severity' => 'WARN', 'line' => 1, 'message' => 'Must start with comment header containing migration_id or filename'];
        }

        if (stripos($content, 'DROP DATABASE') !== false) {
            foreach ($lines as $i => $line) {
                if (stripos($line, 'DROP DATABASE') !== false) {
                    $issues[] = ['rule' => 'DROP_DATABASE', 'severity' => 'FAIL', 'line' => $i + 1, 'message' => 'DROP DATABASE is disallowed'];
                    break;
                }
            }
        }

        $hasAllowDropTable = stripos($content, '--ALLOW_DROP_TABLE') !== false;
        if (stripos($content, 'DROP TABLE') !== false && !$hasAllowDropTable) {
            $sev = $strict ? 'FAIL' : 'WARN';
            foreach ($lines as $i => $line) {
                if (preg_match('/\bDROP\s+TABLE\b/i', $line) === 1) {
                    $issues[] = ['rule' => 'DROP_TABLE', 'severity' => $sev, 'line' => $i + 1, 'message' => 'DROP TABLE without --ALLOW_DROP_TABLE comment'];
                    break;
                }
            }
        }

        if (stripos($content, 'CREATE TABLE') !== false && stripos($content, 'IF NOT EXISTS') === false) {
            $sev = $strict ? 'FAIL' : 'WARN';
            foreach ($lines as $i => $line) {
                if (preg_match('/\bCREATE\s+TABLE\b/i', $line) === 1 && stripos($line, 'IF NOT EXISTS') === false) {
                    $issues[] = ['rule' => 'CREATE_TABLE_IDEMPOTENT', 'severity' => $sev, 'line' => $i + 1, 'message' => 'CREATE TABLE should use IF NOT EXISTS for idempotency'];
                    break;
                }
            }
        }

        return $issues;
    }
}
