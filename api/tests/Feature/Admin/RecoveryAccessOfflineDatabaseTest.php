<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Services\BackupManifestStore;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class RecoveryAccessOfflineDatabaseTest extends TestCase
{
    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDirectory = storage_path('app/test-offline-recovery-'.Str::random(8));
        mkdir($this->backupDirectory, 0775, true);
        config([
            'backup.directory' => $this->backupDirectory,
            'backup.recovery_secret' => str_repeat('r', 64),
            'app.maintenance.driver' => 'file',
            'cache.default' => 'array',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 1,
        ]);
        DB::purge('pgsql');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDirectory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->backupDirectory);
        parent::tearDown();
    }

    public function test_recovery_login_and_catalog_work_while_postgres_is_unreachable(): void
    {
        $filename = 'ogami-20261026-120005.sql.gz';
        file_put_contents($this->backupDirectory.'/'.$filename, gzencode('backup bytes'));
        $manifest = app(BackupManifestStore::class)->publish($filename);

        $unreachable = false;
        try {
            DB::connection('pgsql')->getPdo();
        } catch (Throwable) {
            $unreachable = true;
        }
        DB::purge('pgsql');
        $this->assertTrue($unreachable, 'The isolated test connection unexpectedly reached PostgreSQL.');

        $token = $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/recovery/session', ['secret' => str_repeat('r', 64)])
            ->assertOk()
            ->json('access_token');

        $this->withToken($token)
            ->getJson('/api/v1/recovery/backups')
            ->assertOk()
            ->assertJsonPath('data.backups.0.id', $manifest['id'])
            ->assertJsonPath('data.backups.0.restorable', true);
    }
}
