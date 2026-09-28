<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class ChatAttachmentService
{
    /**
     * @return array<int,string>
     */
    public function allowedMimes(PDO $pdo): array
    {
        $default = [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        try {
            $st = $pdo->prepare("SELECT config_value FROM chat_config WHERE config_key='allowed_attachment_mime' LIMIT 1");
            $st->execute();
            $raw = (string)($st->fetchColumn() ?: '');
            $arr = json_decode($raw, true);
            if (is_array($arr) && $arr) {
                $out = array_values(array_filter(array_map(static fn($v) => strtolower(trim((string)$v)), $arr), static fn($v) => $v !== ''));
                return $out ?: $default;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $default;
    }

    public function maxSizeBytes(PDO $pdo): int
    {
        $mb = 10;
        try {
            $st = $pdo->prepare("SELECT config_value FROM chat_config WHERE config_key='max_attachment_size_mb' LIMIT 1");
            $st->execute();
            $mb = max(1, (int)($st->fetchColumn() ?: 10));
        } catch (\Throwable $e) {
            $mb = 10;
        }
        return $mb * 1024 * 1024;
    }

    public function normalizeFilesArray(array $files): array
    {
        if (!isset($files['name'])) {
            return [];
        }
        $out = [];
        if (is_array($files['name'])) {
            $n = count($files['name']);
            for ($i = 0; $i < $n; $i++) {
                $out[] = [
                    'name' => (string)($files['name'][$i] ?? ''),
                    'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
                    'error' => (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int)($files['size'][$i] ?? 0),
                ];
            }
            return $out;
        }
        return [[
            'name' => (string)($files['name'] ?? ''),
            'tmp_name' => (string)($files['tmp_name'] ?? ''),
            'error' => (int)($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($files['size'] ?? 0),
        ]];
    }

    public function validateFileMeta(array $f, array $allowedMimes, int $maxSizeBytes): array
    {
        if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Attachment upload error.');
        }
        $orig = trim((string)($f['name'] ?? ''));
        $tmp = (string)($f['tmp_name'] ?? '');
        $size = (int)($f['size'] ?? 0);
        if ($orig === '' || $tmp === '' || !is_file($tmp)) {
            throw new \RuntimeException('Attachment file invalid.');
        }
        if ($size <= 0 || $size > $maxSizeBytes) {
            throw new \RuntimeException('Attachment size invalid.');
        }

        // block suspicious double extensions that include executable markers
        $lowerName = strtolower($orig);
        if (preg_match('/\.(php|phtml|phar|exe|sh|bat|cmd)(\.|$)/i', $lowerName)) {
            throw new \RuntimeException('Attachment extension blocked.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $tmp) : 'application/octet-stream';
        $mime = strtolower(trim($mime));
        if (!in_array($mime, $allowedMimes, true)) {
            throw new \RuntimeException('Attachment mime type not allowed.');
        }
        if (in_array($mime, ['application/x-dosexec', 'application/x-elf', 'application/x-msdownload', 'application/x-sh', 'text/x-php'], true)) {
            throw new \RuntimeException('Attachment binary type blocked.');
        }
        $this->validateContentSignature($tmp, $mime, $orig);

        $ext = pathinfo($lowerName, PATHINFO_EXTENSION);
        if ($ext === '') {
            $ext = $this->mimeToExt($mime);
        }
        if ($ext === '') {
            $ext = 'bin';
        }

        return [
            'original_filename' => $orig,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'tmp_name' => $tmp,
            'ext' => $ext,
        ];
    }

    private function validateContentSignature(string $tmp, string $mime, string $orig): void
    {
        if ($mime === 'application/pdf') {
            $fh = @fopen($tmp, 'rb');
            if (!$fh) {
                throw new \RuntimeException('Attachment signature invalid.');
            }
            $head = (string)fread($fh, 5);
            @fclose($fh);
            if ($head !== '%PDF-') {
                throw new \RuntimeException('Attachment signature invalid.');
            }
            return;
        }

        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($tmp);
            if (!is_array($info) || empty($info[0]) || empty($info[1])) {
                throw new \RuntimeException('Image content invalid.');
            }
            return;
        }

        if (in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true)) {
            $fh = @fopen($tmp, 'rb');
            if (!$fh) {
                throw new \RuntimeException('Office file invalid.');
            }
            $sig = (string)fread($fh, 4);
            @fclose($fh);
            if ($sig !== "PK\x03\x04") {
                throw new \RuntimeException('Office file signature invalid.');
            }

            if (class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive();
                if ($zip->open($tmp) !== true) {
                    throw new \RuntimeException('Office archive invalid.');
                }
                $hasCore = false;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string)$zip->getNameIndex($i);
                    if ($name === '[Content_Types].xml') {
                        $hasCore = true;
                        break;
                    }
                }
                $zip->close();
                if (!$hasCore) {
                    throw new \RuntimeException('Office content invalid.');
                }
            }
            return;
        }

        // conservative fallback: disallow unknown binary container despite allowed MIME config mismatch.
        $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
        if (in_array($ext, ['exe', 'dll', 'msi', 'com', 'scr', 'js', 'jar', 'bat', 'cmd', 'php', 'phar', 'phtml'], true)) {
            throw new \RuntimeException('Attachment extension blocked.');
        }
    }

    public function saveFileToStorage(string $rootPath, array $meta): array
    {
        $subdir = '/storage/chat/attachments/' . date('Y/m');
        $dir = rtrim($rootPath, '/') . $subdir;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create chat storage directory.');
        }
        $stored = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]/i', '', (string)$meta['ext']);
        $abs = $dir . '/' . $stored;
        if (!@move_uploaded_file((string)$meta['tmp_name'], $abs)) {
            if (!@rename((string)$meta['tmp_name'], $abs)) {
                throw new \RuntimeException('Failed to move attachment.');
            }
        }
        $sha = hash_file('sha256', $abs);
        if ($sha === false) {
            throw new \RuntimeException('Failed hashing attachment.');
        }
        return [
            'stored_filename' => $stored,
            'storage_path' => $subdir . '/' . $stored,
            'sha256' => $sha,
        ];
    }

    public function mimeToExt(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => '',
        };
    }
}

