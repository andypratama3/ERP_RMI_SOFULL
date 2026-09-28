<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BackupManifestTest extends TestCase
{
    public function testManifestAndChecksumAreCreated(): void
    {
        $dir = sys_get_temp_dir() . '/erp_manifest_test_' . uniqid('', true);
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/files.zip', 'dummy-zip');
        file_put_contents($dir . '/db.sql.gz', 'dummy-db');

        $res = rmi_create_backup_manifest_and_checksums($dir, 'ERP_RMI_SOFULL', [
            'files_zip' => 'files.zip',
            'db_dump' => 'db.sql.gz',
        ]);

        $this->assertFileExists($res['manifest']);
        $this->assertFileExists($res['checksums']);

        $manifest = json_decode((string)file_get_contents($res['manifest']), true);
        $this->assertSame('ERP_RMI_SOFULL', $manifest['app'] ?? null);
        $this->assertNotEmpty($manifest['sha256']['files_zip'] ?? '');
        $this->assertNotEmpty($manifest['sha256']['db_dump'] ?? '');
    }
}

