<?php
declare(strict_types=1);
/**
 * Lib bersama: build laporan sumber navigasi paralel + tulis JSON/MD.
 * Dipakai oleh CLI nav_parallel_sources_report.php dan rbac/nav_parallel_report.php.
 */
if (!function_exists('nav_parallel_bootstrap_scan_roots')) {
    /**
     * Folder tingkat-1 di repo yang di-scan untuk file *_bootstrap.php (semua modul ERP, bukan daftar manual).
     *
     * @return list<string>
     */
    function nav_parallel_bootstrap_scan_roots(string $root): array
    {
        $exclude = [
            'exports', 'vendor', 'android_app', 'storage', '_backup', 'tests', 'testsprite_tests',
            'TEMPLATES', 'node_modules', '.git', 'uploads', 'public', 'bin', 'sql', 'scripts',
        ];
        $out = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (in_array($name, $exclude, true)) {
                continue;
            }
            if (str_starts_with($name, '.')) {
                continue;
            }
            $full = $root . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($full)) {
                continue;
            }
            $out[] = $name;
        }
        sort($out);

        return $out;
    }
}

if (!function_exists('nav_parallel_app_php_tree_count')) {
    /**
     * Jumlah file .php di pohon aplikasi (abaikan vendor/export/test/storage).
     */
    function nav_parallel_app_php_tree_count(string $root): int
    {
        $skipRelPrefixes = [
            'exports/', 'vendor/', 'android_app/', 'storage/', '_backup/', 'tests/', 'testsprite_tests/',
            'node_modules/', '.git/', 'TEMPLATES/',
        ];
        $n = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with(strtolower($file->getFilename()), '.php')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach ($skipRelPrefixes as $pre) {
                if (str_starts_with($rel, $pre)) {
                    continue 2;
                }
            }
            $n++;
        }

        return $n;
    }
}

if (!function_exists('nav_parallel_sources_build')) {
    /**
     * @return array{
     *   generated_at: string,
     *   app_root: string,
     *   counts: array<string,int>,
     *   gaps: array<string,array<int,string>>,
     *   sources: array<string,mixed>
     * }
     */
    function nav_parallel_sources_build(string $root): array
    {
        $root = rtrim($root, '/\\');

        $norm = static function (string $p): string {
            $p = trim(str_replace(['{base}', '\\'], ['', '/'], $p));
            $p = '/' . ltrim($p, '/');
            $p = preg_replace('#/+#', '/', $p) ?? $p;
            return ltrim($p, '/');
        };

        $exists = static function (string $rel, string $root): bool {
            $rel = ltrim($rel, '/');
            return $rel !== '' && is_file($root . '/' . $rel);
        };

        $rmiLayoutPath = $root . '/_shared/rmi_layout.php';
        $uMap = [];
        if (is_file($rmiLayoutPath)) {
            $raw = (string)file_get_contents($rmiLayoutPath);
            if (preg_match_all(
                "/'([a-z0-9_]+)'\s*=>\s*\\\$baseProject\s*\.\s*'([^']+)'/i",
                $raw,
                $m,
                PREG_SET_ORDER
            )) {
                foreach ($m as $row) {
                    $uMap[$row[1]] = $norm($row[2]);
                }
            }
        }

        $navPath = $root . '/_shared/nav_config.php';
        $navItems = [];
        $navPaths = [];
        if (is_file($navPath)) {
            $cfg = require $navPath;
            if (is_array($cfg)) {
                foreach ($cfg as $variant => $items) {
                    if (!is_array($items)) {
                        continue;
                    }
                    foreach ($items as $it) {
                        if (!is_array($it) || empty($it['key'])) {
                            continue;
                        }
                        $key = (string)$it['key'];
                        $path = '';
                        if (!empty($it['url']) && is_string($it['url'])) {
                            $path = $norm($it['url']);
                        } elseif (isset($uMap[$key])) {
                            $path = $uMap[$key];
                        }
                        $navItems[] = [
                            'variant' => (string)$variant,
                            'key' => $key,
                            'label' => (string)($it['label'] ?? ''),
                            'path' => $path,
                            'path_resolved_from' => (!empty($it['url']) ? 'nav:url' : ($path !== '' ? 'rmi_layout:$u' : 'none')),
                            'roles' => (string)($it['roles'] ?? ''),
                            'file_exists' => $path !== '' && $exists($path, $root),
                        ];
                        if ($path !== '') {
                            $navPaths[$path] = true;
                        }
                    }
                }
            }
        }

        $regPaths = [];
        $regFile = $root . '/config/page_registry.php';
        if (is_file($regFile)) {
            $registrySections = require $regFile;
            if (is_array($registrySections)) {
                foreach ($registrySections as $section => $rows) {
                    if (!is_array($rows)) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if (!is_array($row) || empty($row['url'])) {
                            continue;
                        }
                        $p = $norm((string)$row['url']);
                        $regPaths[$p] = [
                            'section' => (string)$section,
                            'label' => (string)($row['label'] ?? ''),
                        ];
                    }
                }
            }
        }

        $modCardPaths = [];
        $masterData = $root . '/master/master_data.php';
        if (is_file($masterData)) {
            $rawMd = (string)file_get_contents($masterData);
            if (preg_match_all(
                "/mod_card\\([^)]*'([^']*\\.php)'\\s*,\\s*'([^']*\\.php)'\\s*\\)/",
                $rawMd,
                $m2,
                PREG_SET_ORDER
            )) {
                foreach ($m2 as $row) {
                    $file = $row[1];
                    $href = $row[2];
                    if (str_starts_with($href, '../')) {
                        $rel = substr($href, 3);
                    } else {
                        $rel = 'master/' . ltrim($href, '/');
                    }
                    $rel = $norm($rel);
                    $modCardPaths[$rel] = ['href_raw' => $href, 'file_attr' => $file, 'exists' => $exists($rel, $root)];
                }
            }
        }

        $bootstrapPaths = [];
        $bootstrapScanRoots = nav_parallel_bootstrap_scan_roots($root);
        $allowedRouteRootsLower = array_map(static fn (string $s): string => strtolower($s), $bootstrapScanRoots);

        $bootstrapFiles = [];
        foreach ($bootstrapScanRoots as $sub) {
            $dir = $root . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '_bootstrap.php')) {
                    $bootstrapFiles[] = $file->getPathname();
                }
            }
        }

        $bootstrapPathIsNavCandidate = static function (string $pNorm): bool {
            if ($pNorm === '') {
                return false;
            }
            $base = basename($pNorm);
            if ($base === '' || str_starts_with($base, '.')) {
                return false;
            }
            // Skrip util / include (bukan menu user)
            if (str_starts_with($base, '_')) {
                return false;
            }
            if (preg_match('/_(bootstrap|lib|scope|helper|audit|gate|trash)\.php$/i', $base)) {
                return false;
            }
            foreach (explode('/', $pNorm) as $seg) {
                if ($seg !== '' && str_starts_with($seg, '_')) {
                    return false;
                }
            }
            return true;
        };

        foreach ($bootstrapFiles as $full) {
            $relDir = str_replace('\\', '/', substr($full, strlen($root) + 1));
            $content = (string)file_get_contents($full);
            $found = [];
            // Hanya pemanggilan helper URL eksplisit (bukan regex generik — menghindari require/_shared noise)
            if (preg_match_all(
                '/\b(?:wqs_url|purl_url|fin_u|scm_u|act_u|br_u|chat_u|ds_panduan_u|hrlp_u|hrl_u|erp_[a-z0-9_]+_url|u)\s*\(\s*[\'"]([^\'"]+\.php[^\'"]*)[\'"]\s*\)/i',
                $content,
                $mm
            )) {
                foreach ($mm[1] as $u) {
                    $n = $norm($u);
                    if ($bootstrapPathIsNavCandidate($n)) {
                        $found[$n] = true;
                    }
                }
            }
            // Literal route di dashboard KPI (return $bp . '/kpi/....php')
            if (str_contains($relDir, 'dashboards/') && preg_match_all("#'(/kpi/[a-z0-9_]+\.php)'#i", $content, $km)) {
                foreach ($km[1] as $u) {
                    $n = $norm($u);
                    if ($bootstrapPathIsNavCandidate($n)) {
                        $found[$n] = true;
                    }
                }
            }
            // String '/modul/...php' yang first segment = folder modul ERP (whitelist), hindari _shared/require noise
            if (preg_match_all('/[\'"](\/[a-zA-Z0-9_\/-]+\.php)[\'"]/', $content, $mm2)) {
                foreach ($mm2[1] as $u) {
                    $n = $norm($u);
                    $first = strtolower(explode('/', $n, 2)[0] ?? '');
                    if ($first === '' || !in_array($first, $allowedRouteRootsLower, true)) {
                        continue;
                    }
                    if ($bootstrapPathIsNavCandidate($n)) {
                        $found[$n] = true;
                    }
                }
            }
            if ($found !== []) {
                $bootstrapPaths[$relDir] = array_keys($found);
            }
        }

        $bootstrapFlat = [];
        foreach ($bootstrapPaths as $file => $paths) {
            foreach ($paths as $p) {
                $bootstrapFlat[$p] = $bootstrapFlat[$p] ?? [];
                $bootstrapFlat[$p][] = $file;
            }
        }

        $allNav = array_keys($navPaths);
        $allReg = array_keys($regPaths);
        $allMod = array_keys($modCardPaths);
        $allBoot = array_keys($bootstrapFlat);

        $inRegNotNav = array_values(array_diff($allReg, $allNav));
        $inNavNotReg = array_values(array_diff($allNav, $allReg));
        $inBootNotNav = array_values(array_diff($allBoot, $allNav));
        // Bootstrap sering mereferensikan entry auth (bukan item menu); jangan jadikan "gap" nav.
        $bootstrapAuthNoise = [
            'master/auth.php',
            'master/login.php',
            'master/logout.php',
        ];
        $inBootNotNav = array_values(array_diff($inBootNotNav, $bootstrapAuthNoise));
        $inModNotNav = array_values(array_diff($allMod, $allNav));

        sort($inRegNotNav);
        sort($inNavNotReg);
        sort($inBootNotNav);
        sort($inModNotNav);

        $phpTreeCount = nav_parallel_app_php_tree_count($root);

        return [
            'generated_at' => date(DATE_ATOM),
            'app_root' => $root,
            'counts' => [
                'rmi_layout_u_keys' => count($uMap),
                'nav_items' => count($navItems),
                'nav_distinct_paths' => count($navPaths),
                'page_registry_paths' => count($regPaths),
                'mod_card_paths' => count($modCardPaths),
                'bootstrap_scan_root_folders' => count($bootstrapScanRoots),
                'bootstrap_files_scanned' => count($bootstrapPaths),
                'bootstrap_distinct_paths' => count($bootstrapFlat),
                'php_files_app_tree_excl_vendor_exports' => $phpTreeCount,
            ],
            'gaps' => [
                'in_page_registry_not_in_sidebar_nav' => $inRegNotNav,
                'in_sidebar_nav_not_in_page_registry' => $inNavNotReg,
                'in_bootstrap_toolbar_not_in_sidebar_nav' => $inBootNotNav,
                'in_master_mod_card_not_in_sidebar_nav' => $inModNotNav,
            ],
            'sources' => [
                'rmi_layout_u' => $uMap,
                'nav_config_items' => $navItems,
                'page_registry_flat' => $regPaths,
                'mod_card' => $modCardPaths,
                'bootstrap_per_file' => $bootstrapPaths,
                'bootstrap_path_to_files' => $bootstrapFlat,
                'bootstrap_scan_roots' => $bootstrapScanRoots,
            ],
        ];
    }
}

if (!function_exists('nav_parallel_sources_write_artifacts')) {
    function nav_parallel_sources_write_artifacts(string $root, array $payload): void
    {
        $root = rtrim($root, '/\\');
        $outJson = $root . '/storage/logs/nav_parallel_report.json';
        $outMd = $root . '/storage/logs/nav_parallel_report.md';
        @mkdir(dirname($outJson), 0775, true);

        file_put_contents($outJson, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

        $inRegNotNav = $payload['gaps']['in_page_registry_not_in_sidebar_nav'] ?? [];
        $inNavNotReg = $payload['gaps']['in_sidebar_nav_not_in_page_registry'] ?? [];
        $inBootNotNav = $payload['gaps']['in_bootstrap_toolbar_not_in_sidebar_nav'] ?? [];
        $inModNotNav = $payload['gaps']['in_master_mod_card_not_in_sidebar_nav'] ?? [];
        $bootstrapFlat = $payload['sources']['bootstrap_path_to_files'] ?? [];
        $modCardPaths = $payload['sources']['mod_card'] ?? [];
        $counts = $payload['counts'] ?? [];

        $md = [];
        $md[] = '# Nav parallel sources report';
        $md[] = '';
        $md[] = '**Dibuat:** ' . ($payload['generated_at'] ?? '');
        $md[] = '';
        $md[] = '## Cara me-review';
        $md[] = '';
        $md[] = '1. **`rbac/nav_parallel_report.php`** — tampilan web (RBAC Center).';
        $md[] = '2. **`storage/logs/nav_parallel_report.json`** — detail penuh.';
        $md[] = '3. Regenerate CLI: `php tools/rbac/nav_parallel_sources_report.php`';
        $md[] = '';
        $md[] = '## Ringkasan jumlah';
        $md[] = '';
        $md[] = '| Sumber | Jumlah |';
        $md[] = '|--------|--------:|';
        $md[] = '| Keys `$u` di rmi_layout | ' . ($counts['rmi_layout_u_keys'] ?? 0) . ' |';
        $md[] = '| Item nav_config (semua variant) | ' . ($counts['nav_items'] ?? 0) . ' |';
        $md[] = '| Path unik sidebar | ' . ($counts['nav_distinct_paths'] ?? 0) . ' |';
        $md[] = '| Path di page_registry | ' . ($counts['page_registry_paths'] ?? 0) . ' |';
        $md[] = '| Path dari mod_card | ' . ($counts['mod_card_paths'] ?? 0) . ' |';
        $md[] = '| Folder tingkat-1 untuk scan bootstrap | ' . ($counts['bootstrap_scan_root_folders'] ?? 0) . ' |';
        $md[] = '| File *_bootstrap.php berisi tautan | ' . ($counts['bootstrap_files_scanned'] ?? 0) . ' |';
        $md[] = '| Path unik dari bootstrap | ' . ($counts['bootstrap_distinct_paths'] ?? 0) . ' |';
        $md[] = '| File .php pohon app (tanpa vendor/export/…) | ' . ($counts['php_files_app_tree_excl_vendor_exports'] ?? 0) . ' |';
        $md[] = '';

        $md[] = '## Gap: Page Registry tidak ada di path sidebar';
        $md[] = '';
        foreach (array_slice($inRegNotNav, 0, 80) as $line) {
            $md[] = '- `' . $line . '`';
        }
        if (count($inRegNotNav) > 80) {
            $md[] = '- _… +' . (count($inRegNotNav) - 80) . ' lainnya_';
        }
        $md[] = '';

        $md[] = '## Gap: Sidebar path tidak ada di Page Registry';
        $md[] = '';
        foreach (array_slice($inNavNotReg, 0, 80) as $line) {
            $md[] = '- `' . $line . '`';
        }
        if (count($inNavNotReg) > 80) {
            $md[] = '- _… +' . (count($inNavNotReg) - 80) . ' lainnya_';
        }
        $md[] = '';

        $md[] = '## Gap: URL di *_bootstrap.php tidak ada di sidebar path';
        $md[] = '';
        foreach (array_slice($inBootNotNav, 0, 60) as $line) {
            $files = $bootstrapFlat[$line] ?? [];
            $md[] = '- `' . $line . '` ← ' . implode(', ', array_slice($files, 0, 3)) . (count($files) > 3 ? ' …' : '');
        }
        if (count($inBootNotNav) > 60) {
            $md[] = '- _… +' . (count($inBootNotNav) - 60) . ' lainnya_';
        }
        $md[] = '';

        $md[] = '## Gap: mod_card (Master Hub) tidak ada di sidebar path';
        $md[] = '';
        foreach ($inModNotNav as $line) {
            $meta = is_array($modCardPaths[$line] ?? null) ? $modCardPaths[$line] : [];
            $md[] = '- `' . $line . '` (href: `' . ($meta['href_raw'] ?? '') . '`)';
        }

        file_put_contents($outMd, implode("\n", $md) . "\n");
    }
}
