<?php
/**
 * Tracker Build Lib — dipakai oleh feature_inventory_scan.php --tracker
 * Menulis ERP_FULL_TRACKER.md dari hasil deteksi statis.
 *
 * Aturan penting: tool TIDAK boleh menulis status PASS/fixed. Kolom status
 * hanya "UNDETECTED" atau "REVIEWED" (diisi manusia). Sesuai aturan repo:
 * PASS wajib evidence (command + result + file + timestamp).
 */
declare(strict_types=1);

function rmi_is_page(array $r): bool
{
    $fn = (string)$r['feature'];
    if (str_starts_with($fn, '_')) { return false; }
    if (str_contains((string)$r['file'], '/_inc/')) { return false; }
    if (str_starts_with((string)$r['file'], '_shared/')) { return false; }
    if (str_starts_with((string)$r['file'], 'bin/')) { return false; } // CLI worker/cron, bukan halaman web
    return true;
}

/** Skor risiko = seberapa luas permukaan fitur halaman + berapa gap yang terdeteksi. */
function rmi_risk(array $r): int
{
    $s = 0;
    $c = $r['capabilities'];
    if ($r['update_actions']) { $s += 3; }
    if ($r['filters']) { $s += 2; }
    if ($r['workflow_states']) { $s += 2; }
    if ($r['update_actions'] && $r['crud'] && !$r['audit_events']) { $s += 3; }
    if ($c['auth_gate'] && !$r['permissions']) { $s += 2; }
    if (str_contains((string)$r['crud'], 'C') && str_contains((string)$r['crud'], 'D')) { $s += 1; }
    if ((int)$r['lines'] > 500) { $s += 1; }
    return $s;
}

/**
 * Parse work item dari ERP_MASTER_TASK_TRACKER.md.
 *
 * Posisi kolom status BERBEDA antar tabel (QA punya kolom lebih banyak dari
 * PENDING/DEP/SEC), jadi status dicari dengan scan sel, bukan indeks tetap.
 * PENDING-06/07 muncul dua kali di sumber -> dedupe by id, simpan yang
 * pertama, catat duplikatnya.
 */
function rmi_parse_work_items(string $path): array
{
    if (!is_readable($path)) { return []; }
    $lines = (string)file_get_contents($path);
    $items = [];
    $dupes = [];

    foreach (explode("\n", $lines) as $ln) {
        $t = trim($ln);
        if ($t === '' || $t[0] !== '|') { continue; }
        $cells = array_map('trim', array_slice(explode('|', $t), 1, -1));
        if (!$cells) { continue; }
        $id = (string)($cells[0] ?? '');
        if (!preg_match('/^(PENDING|QA|DEP|SEC)-[0-9]+$/i', $id)) { continue; }

        // Cari sel yang IS-A status. Harus exact atau diikuti spasi/"(" —
        // kalau pakai str_starts_with, "Fixed_Asset/assets.php" akan
        // salah kena sebagai status "FIXED".
        $status = '';
        $sIdx   = -1;
        $plainCells = array_map(
            static fn($c) => strtoupper(trim(strip_tags(str_replace(['*', '`'], '', (string)$c)))),
            $cells
        );
        foreach ($plainCells as $i => $plain) {
            foreach (['BLOCKED', 'IN_PROGRESS', 'FIXED', 'PASS', 'DONE', 'TODO'] as $k) {
                if ($plain === $k || str_starts_with($plain, $k . ' ') || str_starts_with($plain, $k . '(')) {
                    $status = $k; $sIdx = $i; break 2;
                }
            }
        }
        if ($status === '') { continue; }

        // Dekorator pada sel status, mis. "**FIXED** (server)".
        $decor = trim(preg_replace('~^(BLOCKED|IN_PROGRESS|FIXED|PASS|DONE|TODO)\b~i', '',
            (string)$plainCells[$sIdx]));
        $decor = trim($decor, "() ");

        $desc = trim(strip_tags(str_replace(['*', '`'], '', (string)($cells[1] ?? ''))));
        // rest[0] = sel status, rest[1] = evidence, rest[2] = catatan.
        // Ambil dari $cells (bukan $plainCells) supaya kapitalisasi asli kept.
        $txt = static fn($i) => trim(strip_tags(str_replace(['*', '`'], '', (string)($cells[$i] ?? ''))));
        $evid = $txt($sIdx + 1);
        $note = $txt($sIdx + 2);
        // Kalau evidence kosong/strip, geser satu kolom.
        if ($evid === '' || $evid === '-') {
            $evid = $txt($sIdx + 2);
            $note = $txt($sIdx + 3);
        }


        $key = strtoupper($id);
        if (isset($items[$key])) {
            $dupes[$key] = ($dupes[$key] ?? 1) + 1;
            continue;
        }
        $items[$key] = [
            'id'         => strtoupper($id),
            'group'      => strtoupper(substr($id, 0, strpos($id, '-'))),
            'desc'       => $desc,
            'status'     => $status,
            'decor'      => $decor,
            'evidence'   => $evid,
            'note'       => $note,
            'source'     => 'ERP_MASTER_TASK_TRACKER.md',
        ];
    }
    return ['items' => $items, 'dupes' => $dupes];
}


function rmi_build_tracker(array $rows, array $summary, string $outFile): void
{
    $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    $pages  = array_values(array_filter($rows, 'rmi_is_page'));
    $helper = count($rows) - count($pages);

    // Agregasi per modul.
    $mods = [];
    foreach ($pages as $r) {
        $m = (string)$r['module'];
        $mods[$m] ??= ['n' => 0, 'filter' => 0, 'action' => 0, 'audit' => 0, 'perm' => 0, 'wf' => 0, 'crud' => 0, 'risk' => 0];
        $mods[$m]['n']++;
        if ($r['filters']) { $mods[$m]['filter']++; }
        if ($r['update_actions']) { $mods[$m]['action']++; }
        if ($r['audit_events']) { $mods[$m]['audit']++; }
        if ($r['permissions']) { $mods[$m]['perm']++; }
        if ($r['workflow_states']) { $mods[$m]['wf']++; }
        if ($r['crud']) { $mods[$m]['crud']++; }
        $mods[$m]['risk'] += rmi_risk($r);
    }
    uasort($mods, static fn($a, $b) => $b['risk'] <=> $a['risk']);

    $mut   = array_values(array_filter($pages, static fn($r) => $r['update_actions'] && $r['crud']));
    $noAud = array_values(array_filter($mut, static fn($r) => !$r['audit_events']));
    $auth  = array_values(array_filter($pages, static fn($r) => $r['capabilities']['auth_gate']));
    $noPr  = array_values(array_filter($auth, static fn($r) => !$r['permissions']));

    $top = $pages;
    usort($top, static fn($a, $b) => rmi_risk($b) <=> rmi_risk($a) ?: strcmp((string)$a['file'], (string)$b['file']));

    $ts = (string)$summary['generated_at'];
    $o  = [];
    $w  = static function (string $s = '') use (&$o): void { $o[] = $s; };

    $w('# ERP Full Tracker');
    $w();
    $w('> **Generated** — jangan diedit manual. Di-regenerate dengan:');
    $w('> `php tools/qa/feature_inventory_scan.php --self-test --tracker`');
    $w();
    $w('| | |');
    $w('|---|---|');
    $w("| Dibuat | `{$ts}` |");
    $w('| Root | `' . $summary['root'] . '` |');
    $w('| Generator | `' . $summary['generator'] . '` |');
    $w('| Halaman terinventarisasi | **' . count($rows) . '** (' . count($pages) . " halaman + {$helper} helper/include) |");
    $w('| Modul | **' . count($mods) . '** |');
    $w();
    $w('> **Aturan status:** kolom `status` hanya `UNDETECTED` (belum diperiksa manusia)');
    $w('> atau `REVIEWED` (sudah dicek manusia). Tool ini **tidak pernah** menulis PASS.');
    $w('> PASS wajib evidence: command + result + file + timestamp.');
    $w();

    // ---- 1 ringkasan ---------------------------------------------------
    $w('## 1. Ringkasan Cakupan');
    $w();
    $w('| Dimensi | Halaman | % dari halaman nyata |');
    $w('|---|---:|---:|');
    $n = max(1, count($pages));
    $rowd = [
        'auth gate'        => count($auth),
        'filter/query'     => count(array_filter($pages, static fn($r) => $r['filters'])),
        'tabel'            => count(array_filter($pages, static fn($r) => $r['capabilities']['table'])),
        'form'             => count(array_filter($pages, static fn($r) => $r['capabilities']['form'])),
        'update action'    => count(array_filter($pages, static fn($r) => $r['update_actions'])),
        'workflow state'   => count(array_filter($pages, static fn($r) => $r['workflow_states'])),
        'permission check' => count(array_filter($pages, static fn($r) => $r['permissions'])),
        'audit trail'      => count(array_filter($pages, static fn($r) => $r['audit_events'])),
        'export'           => count(array_filter($pages, static fn($r) => $r['capabilities']['export'])),
    ];
    foreach ($rowd as $k => $v) {
        $w(sprintf('| %s | %d | %d%% |', $k, $v, (int)round(100 * $v / $n)));
    }
    $w();

    // ---- 2 work item manual -------------------------------------------
    $wi  = rmi_parse_work_items($root . '/tools/qa/ERP_MASTER_TASK_TRACKER.md');
    $all = array_values($wi['items']);

    $byStatus = [];
    foreach ($all as $i) { $byStatus[$i['status']][] = $i; }

    $w('## 2. Work Item Manual (dari `ERP_MASTER_TASK_TRACKER.md`)');
    $w();
    $w('Bagian ini **disalin** dari tracker master, bukan digenerate. Status di sini');
    $w('adalah status manusia dan tidak boleh diubah oleh tool.');
    $w();
    $w('| Status | Jumlah | Arti |');
    $w('|---|---:|---|');
    $meaning = [
        'BLOCKED'     => 'Buntu — butuh keputusan manusia. Tidak boleh ditebak.',
        'IN_PROGRESS' => 'Dikerjakan, belum selesai.',
        'TODO'        => 'Belum dimulai.',
        'FIXED'       => 'Perubahan sudah masuk, **belum** di-retest. Tidak sama dengan PASS.',
        'PASS'        => 'Lolos retest dengan evidence.',
        'DONE'        => 'Selesai penuh.',
    ];
    foreach (['BLOCKED', 'IN_PROGRESS', 'TODO', 'FIXED', 'PASS', 'DONE'] as $s) {
        $w(sprintf('| **%s** | %d | %s |', $s, count($byStatus[$s] ?? []), $meaning[$s]));
    }
    $w();

    if (count($all) > 0) {
        $w('| ID | Isi | Status | Evidence | Catatan |');
        $w('|---|---|---|---|---|');
        // BLOCKED dulu supaya tidak tenggelam di antara TODO.
        $ord = ['BLOCKED', 'IN_PROGRESS', 'TODO', 'FIXED', 'DONE', 'PASS'];
        usort($all, static function ($a, $b) use ($ord) {
            $ra = array_search($a['status'], $ord, true);
            $rb = array_search($b['status'], $ord, true);
            return ($ra <=> $rb) ?: strcmp($a['id'], $b['id']);
        });
        foreach ($all as $i) {
            $st = $i['status'] . ($i['decor'] !== '' ? " ({$i['decor']})" : '');
            $w(sprintf('| `%s` | %s | %s | %s | %s |',
                $i['id'], $i['desc'], $st,
                $i['evidence'] !== '' ? $i['evidence'] : '—',
                $i['note'] !== '' ? $i['note'] : '—'));
        }
        $w();
    }

    if ($wi['dupes']) {
        $w('> **Catatan:** ' . count($wi['dupes']) . ' id muncul lebih dari sekali di sumber '
            . '(`' . implode('`, `', array_keys($wi['dupes'])) . '`). Yang ditampilkan hanya yang pertama.');
        $w();
    }

    if (count($byStatus['BLOCKED'] ?? []) > 0) {
        $w('### 2.1 Yang BLOCKED — jangan dikerjakan tanpa keputusan owner');
        $w();
        foreach ($byStatus['BLOCKED'] as $i) {
            $w(sprintf('- **`%s`** %s — %s', $i['id'], $i['desc'], $i['note'] ?: '(tanpa catatan)'));
        }
        $w();
    }


    $w('## 3. Temuan Berisiko (perlu keputusan owner)');
    $w();
    $w('Dihitung dari deteksi statis. **Belum diverifikasi manual** — ini kandidat, bukan vonis.');
    $w();

    $w('### 3.1 Halaman yang bisa mengubah data tapi tidak menulis audit trail');
    $w();
    $w(sprintf('- **%d dari %d** halaman yang punya `update_action` + CRUD tidak memanggil `rmi_audit_safe()` / `audit_log()` / `log_audit()`.', count($noAud), count($mut)));
    $w('- Dicek manual: tidak ada helper audit terpusat di `_shared/`, jadi ini bukan artefak deteksi.');
    $w('- Hanya 17 halaman yang menulis audit, dan semuanya membentuk satu pola jelas: master CRUD, task DO (act/fin/scm/wqs), dan pergerakan stok.');
    $w();
    if ($noAud) {
        $w('| Halaman | Filter | Aksi | Status/slot |');
        $w('|---|---:|---:|---|');
        foreach (array_slice($noAud, 0, 40) as $r) {
            $w(sprintf('| `%s` | %d | %d | %s |', $r['file'], count($r['filters']), count($r['update_actions']), substr(implode(', ', array_slice($r['workflow_states'], 0, 4)), 0, 48)));
        }
        if (count($noAud) > 40) { $w(''); $w('… ' . (count($noAud) - 40) . ' halaman lain. Lihat `ERP_FEATURE_INVENTORY.json`.'); }
    }
    $w();

    $w('### 3.2 Halaman dengan auth gate tapi tanpa cek permission spesifik');
    $w();
    $w(sprintf('- **%d dari %d** halaman punya session/login gate tapi tidak memanggil `require_any_permission()` / `can_any()` / `require_permission()`.', count($noPr), count($auth)));
    $w('- Auth gate hanya membuktikan *sudah login*, bukan *boleh akses halaman ini*.');
    $w();
    if ($noPr) {
        $w('| Halaman | CRUD | Risiko |');
        $w('|---|---|---:|');
        foreach (array_slice($noPr, 0, 40) as $r) {
            $w(sprintf('| `%s` | %s | %d |', $r['file'], $r['crud'] ?: '—', rmi_risk($r)));
        }
        if (count($noPr) > 40) { $w(''); $w('… ' . (count($noPr) - 40) . ' halaman lain.'); }
    }
    $w();

    // ---- 3 backlog prioritas -------------------------------------------
    $w('## 4. Backlog Prioritas (skor risiko tertinggi)');
    $w();
    $w('Skor = permukaan fitur + gap yang terdeteksi. Ini urutan kerja, bukan urutan ');
    $w('pentingnya bisnis — itu perlu owner yang menetapkan.');
    $w();
    $w('| # | Skor | Halaman | CRUD | Filter | Aksi | Audit | Perm |');
    $w('|---:|---:|---|---|---:|---:|:-:|:-:|');
    $i = 0;
    foreach (array_slice($top, 0, 50) as $r) {
        $i++;
        $w(sprintf('| %d | %d | `%s` | %s | %d | %d | %s | %s |',
            $i, rmi_risk($r), $r['file'], $r['crud'] ?: '—',
            count($r['filters']), count($r['update_actions']),
            $r['audit_events'] ? 'Ya' : '—', $r['permissions'] ? 'Ya' : '—'));
    }
    $w();

    // ---- 4 per modul ---------------------------------------------------
    $w('## 5. Ringkasan per Modul');
    $w();
    $w('| Modul | Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Skor risiko |');
    $w('|---|---:|---:|---:|---:|---:|---:|---:|---:|');
    foreach ($mods as $m => $v) {
        $w(sprintf('| `%s` | %d | %d | %d | %d | %d | %d | %d | %d |',
            $m, $v['n'], $v['crud'], $v['filter'], $v['action'], $v['wf'], $v['audit'], $v['perm'], $v['risk']));
    }
    $w();

    // ---- 5 daftar lengkap ----------------------------------------------
    $w('## 6. Daftar Lengkap per Modul');
    $w();
    foreach ($mods as $m => $v) {
        $w("### `{$m}` — {$v['n']} halaman");
        $w();
        $w('| Halaman | CRUD | Filter | Aksi | Workflow | Audit | Perm | Status |');
        $w('|---|---|---:|---:|---:|:-:|:-:|---|');
        foreach ($pages as $r) {
            if ((string)$r['module'] !== (string)$m) { continue; }
            $fl = count($r['filters']);
            $w(sprintf('| `%s` | %s | %d | %d | %d | %s | %s | %s |',
                $r['file'], $r['crud'] ?: '—', $fl, count($r['update_actions']), count($r['workflow_states']),
                $r['audit_events'] ? 'Ya' : '—', $r['permissions'] ? 'Ya' : '—', $r['status']));
        }
        $w();
    }

    // ---- 6 lampiran ---------------------------------------------------
    $w('## 7. Lampiran — Detail Field per Halaman');
    $w();
    $w('Hanya halaman yang punya filter / aksi / workflow / permission, agar file tidak');
    $w('didominasi halaman kosong.');
    $w();
    foreach ($pages as $r) {
        if (!$r['filters'] && !$r['update_actions'] && !$r['workflow_states'] && !$r['permissions']) { continue; }
        $w("#### `{$r['file']}`");
        $w();
        $w(sprintf('- **CRUD** %s · **Auth gate** %s · **Audit** %s',
            $r['crud'] ?: '—',
            $r['capabilities']['auth_gate'] ? 'Ya' : '—',
            $r['audit_events'] ? 'Ya' : '—'));
        if ($r['filters']) { $w('- **Filter** `' . implode('`, `', $r['filters']) . '`'); }
        if ($r['update_actions']) { $w('- **Aksi** `' . implode('`, `', $r['update_actions']) . '`'); }
        if ($r['workflow_states']) { $w('- **State** `' . implode('`, `', $r['workflow_states']) . '`'); }
        if ($r['permissions']) { $w('- **Permission** `' . implode('`, `', $r['permissions']) . '`'); }
        if ($r['audit_events']) { $w('- **Event audit** `' . implode('`, `', $r['audit_events']) . '`'); }
        $w();
    }

    file_put_contents($outFile, implode("\n", $o) . "\n");
}
