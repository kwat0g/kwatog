<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Jobs\RestoreBackupJob;
use App\Common\Models\BackupOperation;
use App\Common\Services\BackupManifestStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Routing\Router;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class RecoveryAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDirectory;

    private const RECOVERY_SECRET = 'recovery-secret-for-tests-'.'01234567890123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDirectory = storage_path('app/test-recovery-'.Str::random(8));
        mkdir($this->backupDirectory, 0775, true);
        config([
            'backup.directory' => $this->backupDirectory,
            'backup.recovery_secret' => self::RECOVERY_SECRET,
            'backup.s3_bucket' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDirectory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->backupDirectory);
        parent::tearDown();
    }

    public function test_recovery_login_works_without_erp_users_or_database_sessions(): void
    {
        $this->assertDatabaseCount('users', 0);

        $route = app(Router::class)->getRoutes()->match(Request::create('/api/v1/recovery/session', 'POST'));
        $middleware = app(Router::class)->gatherRouteMiddleware($route);
        $this->assertNotContains(EnsureFrontendRequestsAreStateful::class, $middleware);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/recovery/session', ['secret' => self::RECOVERY_SECRET])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'expires_in']);
    }

    public function test_recovery_secret_lists_and_queues_restore_from_a_sidecar(): void
    {
        Queue::fake();
        $databaseFilename = 'ogami-20261026-120000.sql.gz';
        file_put_contents($this->backupDirectory.'/'.$databaseFilename, $this->validDump());
        $manifest = app(BackupManifestStore::class)->publish($databaseFilename);
        $token = $this->token();

        $this->withToken($token)
            ->getJson('/api/v1/recovery/backups')
            ->assertOk()
            ->assertJsonPath('data.backups.0.id', $manifest['id'])
            ->assertJsonPath('data.backups.0.restorable', true);

        $this->withToken($token)
            ->postJson('/api/v1/recovery/restore', [
                'manifest_id' => $manifest['id'],
                'database_filename' => $databaseFilename,
                'confirmation' => 'RESTORE '.$databaseFilename,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.type', 'restore');

        $operation = BackupOperation::query()->where('type', 'restore')->firstOrFail();
        $this->assertNull($operation->requested_by);
        $this->assertTrue($operation->metadata['requested_via_recovery']);
        Queue::assertPushed(RestoreBackupJob::class);
    }

    public function test_legacy_verification_rejects_non_dump_and_records_valid_dump_checksum(): void
    {
        $token = $this->token();
        $badFilename = 'ogami-20261026-120001.sql.gz';
        file_put_contents($this->backupDirectory.'/'.$badFilename, gzencode('not a PostgreSQL dump'));

        $this->withToken($token)
            ->postJson('/api/v1/recovery/backups/verify', ['database_filename' => $badFilename])
            ->assertStatus(422)
            ->assertJsonValidationErrors('database_filename');

        $incompatibleFilename = 'ogami-20261026-120003.sql.gz';
        file_put_contents($this->backupDirectory.'/'.$incompatibleFilename, gzencode(
            "-- Dumped by pg_dump version 17.1\n-- Dumped from database version 16.2\nSELECT 1;\n",
        ));
        $this->withToken($token)
            ->postJson('/api/v1/recovery/backups/verify', ['database_filename' => $incompatibleFilename])
            ->assertStatus(422)
            ->assertJsonValidationErrors('database_filename');

        $goodFilename = 'ogami-20261026-120002.sql.gz';
        file_put_contents($this->backupDirectory.'/'.$goodFilename, $this->validDump());

        $this->withToken($token)
            ->postJson('/api/v1/recovery/backups/verify', ['database_filename' => $goodFilename])
            ->assertOk();

        $manifest = app(BackupManifestStore::class)->find($goodFilename);
        $this->assertSame('legacy_adopted', $manifest['source']);
        $this->assertSame(hash_file('sha256', $this->backupDirectory.'/'.$goodFilename), $manifest['artifacts']['database']['sha256']);
    }

    public function test_offsite_manifest_survives_local_retention(): void
    {
        $filename = 'ogami-20261026-120004.sql.gz';
        file_put_contents($this->backupDirectory.'/'.$filename, $this->validDump());
        app(BackupManifestStore::class)->publish($filename);
        unlink($this->backupDirectory.'/'.$filename);
        config(['backup.s3_bucket' => 's3://test-backups']);

        app(BackupManifestStore::class)->pruneMissingArtifacts();

        $this->assertNotNull(app(BackupManifestStore::class)->find($filename));
    }

    public function test_incorrect_recovery_secret_and_invalid_token_are_rejected(): void
    {
        $this->postJson('/api/v1/recovery/session', ['secret' => 'wrong'])
            ->assertUnauthorized();

        $this->withToken('invalid')
            ->getJson('/api/v1/recovery/backups')
            ->assertUnauthorized();

        $this->withToken($this->token())
            ->getJson('/api/v1/admin/backups')
            ->assertUnauthorized();
    }

    private function token(): string
    {
        return (string) $this->postJson('/api/v1/recovery/session', ['secret' => self::RECOVERY_SECRET])
            ->assertOk()
            ->json('access_token');
    }

    private function validDump(): string
    {
        return gzencode("-- Dumped by pg_dump version 16.4\n-- Dumped from database version 16.2\nSELECT 1;\n");
    }
}
