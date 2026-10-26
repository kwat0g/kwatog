<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Jobs\RestoreBackupJob;
use App\Common\Services\BackupManifestStore;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
        @unlink($this->backupDirectory.'/.recovery-operation.lock');
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
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('access_token');

        $this->withToken($token)
            ->getJson('/api/v1/recovery/backups')
            ->assertOk()
            ->assertJsonPath('data.backups.0.id', $manifest['id'])
            ->assertJsonPath('data.backups.0.restorable', true);
    }

    public function test_recovery_can_queue_and_track_a_restore_without_the_database_ledger(): void
    {
        Queue::fake();
        $filename = 'ogami-20261026-120006.sql.gz';
        file_put_contents($this->backupDirectory.'/'.$filename, gzencode(
            "-- Dumped by pg_dump version 16.4\n-- Dumped from database version 16.2\nSELECT 1;\n",
        ));
        $manifest = app(BackupManifestStore::class)->publish($filename);
        $token = $this->postJson('/api/v1/recovery/session', ['secret' => str_repeat('r', 64)])
            ->assertOk()
            ->json('access_token');

        $queued = $this->withToken($token)->postJson('/api/v1/recovery/restore', [
            'manifest_id' => $manifest['id'],
            'database_filename' => $filename,
            'confirmation' => 'RESTORE '.$filename,
        ])->assertAccepted();

        $operationId = (string) $queued->json('data.id');
        Queue::assertPushed(RestoreBackupJob::class);
        $this->assertSame(0600, (int) fileperms($this->backupDirectory.'/recovery-operation.json') & 0777);
        $this->withToken($token)
            ->getJson('/api/v1/recovery/operations/'.$operationId)
            ->assertOk()
            ->assertJsonPath('data.id', $operationId)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.rollback_available', false);
    }

    public function test_recovery_operation_routes_require_unexpired_bearer_access(): void
    {
        $operationId = (string) Str::uuid();
        foreach ([
            ['getJson', '/api/v1/recovery/backups'],
            ['postJson', '/api/v1/recovery/backups/verify'],
            ['postJson', '/api/v1/recovery/restore'],
            ['getJson', '/api/v1/recovery/operations/'.$operationId],
            ['postJson', '/api/v1/recovery/operations/'.$operationId.'/rollback'],
        ] as [$method, $path]) {
            $response = $method === 'getJson'
                ? $this->getJson($path)
                : $this->postJson($path);
            $response->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        }

        $expiredToken = Crypt::encryptString(json_encode([
            'scope' => 'backup-recovery',
            'expires_at' => time(),
            'secret_hash' => hash('sha256', str_repeat('r', 64)),
        ], JSON_THROW_ON_ERROR));
        $this->withToken($expiredToken)
            ->getJson('/api/v1/recovery/backups')
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->postJson('/api/v1/recovery/session', [])
            ->assertStatus(422)
            ->assertHeader('Cache-Control', 'no-store, private');

        $token = $this->postJson('/api/v1/recovery/session', ['secret' => str_repeat('r', 64)])
            ->assertOk()
            ->json('access_token');
        config(['backup.recovery_secret' => str_repeat('s', 64)]);
        $this->withToken($token)
            ->getJson('/api/v1/recovery/backups')
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_recovery_login_is_rate_limited_and_errors_are_not_cached(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/recovery/session', ['secret' => 'incorrect'])
                ->assertUnauthorized()
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        $this->postJson('/api/v1/recovery/session', ['secret' => str_repeat('r', 64)])
            ->assertTooManyRequests()
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
