<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Services\BackupManifestStore;
use Illuminate\Support\Str;
use Tests\TestCase;

class RunFullBackupManifestTest extends TestCase
{
    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDirectory = storage_path('app/test-full-backup-'.Str::random(8));
        mkdir($this->backupDirectory, 0775, true);

        $databaseScript = $this->backupDirectory.'/database.sh';
        file_put_contents($databaseScript, <<<'SH'
#!/bin/sh
set -eu
out="$BACKUP_DIR/ogami-20261026-120100.sql.gz"
printf dump > "$out"
printf 'backup written: %s (1 KB)\n' "$out"
SH);

        $filesScript = $this->backupDirectory.'/files.sh';
        file_put_contents($filesScript, <<<'SH'
#!/bin/sh
set -eu
out="$BACKUP_DIR/ogami-files-20261026-120100.tar.gz"
printf files > "$out"
printf 'files backup written: %s (1 KB)\n' "$out"
SH);

        config([
            'backup.directory' => $this->backupDirectory,
            'backup.script' => $databaseScript,
            'backup.files_script' => $filesScript,
            'backup.files_directory' => $this->backupDirectory.'/private',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDirectory.'/*') ?: [] as $file) {
            if (is_dir($file)) {
                @rmdir($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($this->backupDirectory);
        parent::tearDown();
    }

    public function test_full_backup_publishes_sidecar_for_both_artifacts(): void
    {
        $this->artisan('db:full-backup')->assertSuccessful();

        $manifest = app(BackupManifestStore::class)->find('ogami-20261026-120100.sql.gz');

        $this->assertNotNull($manifest);
        $this->assertSame('scheduled', $manifest['source']);
        $this->assertSame('ogami-files-20261026-120100.tar.gz', $manifest['artifacts']['files']['name']);
        $this->assertSame(hash_file('sha256', $this->backupDirectory.'/ogami-20261026-120100.sql.gz'), $manifest['artifacts']['database']['sha256']);
        $this->assertSame(hash_file('sha256', $this->backupDirectory.'/ogami-files-20261026-120100.tar.gz'), $manifest['artifacts']['files']['sha256']);
    }
}
