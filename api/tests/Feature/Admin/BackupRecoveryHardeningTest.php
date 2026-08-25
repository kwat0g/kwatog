<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Models\BackupOperation;
use App\Common\Services\BackupService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recovery-surface guarantees for M012: manifest-bound restore admission, the
 * singleton admission lock, stale-lease reconciliation, and catalog honesty.
 *
 * No test here executes a real restore. The destructive helpers are shelled out
 * to bash by the service, so every case below stops at admission, ledger state,
 * or serialization — a suite that actually restored would drop the database out
 * from under itself.
 */
class BackupRecoveryHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->backupDirectory = storage_path('app/test-backups-'.Str::random(8));
        @mkdir($this->backupDirectory, 0775, true);
        config([
            'backup.directory' => $this->backupDirectory,
            'backup.s3_bucket' => null,
            'backup.lease_seconds' => 7200,
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

    public function test_restore_requires_a_committed_manifest(): void
    {
        Queue::fake();
        $admin = $this->systemAdmin();
        $name = 'ogami-20260825-101500.sql.gz';
        $this->writeArtifact($name, 'unmanaged dump bytes');

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/backups/restore', [
                'database_filename' => $name,
                'confirmation' => 'RESTORE '.$name,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup_operation_id');

        Queue::assertNothingPushed();
    }

    public function test_restore_rejects_an_artifact_that_no_longer_matches_its_manifest(): void
    {
        Queue::fake();
        $admin = $this->systemAdmin();
        $name = 'ogami-20260825-102500.sql.gz';
        $this->writeArtifact($name, 'the bytes that were backed up');
        $manifest = $this->completedBackup($admin, $name);

        // The artifact is swapped for different valid-looking content after the
        // manifest was committed.
        $this->writeArtifact($name, 'different bytes entirely, same filename');

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/backups/restore', [
                'backup_operation_id' => $manifest->id,
                'database_filename' => $name,
                'confirmation' => 'RESTORE '.$name,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('database_filename');

        Queue::assertNothingPushed();
    }

    public function test_restore_from_a_committed_manifest_binds_the_manifest_artifacts(): void
    {
        Queue::fake();
        $admin = $this->systemAdmin();
        $name = 'ogami-20260825-103500.sql.gz';
        $this->writeArtifact($name, 'a coherent dump');
        $manifest = $this->completedBackup($admin, $name);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/backups/restore', [
                'backup_operation_id' => $manifest->id,
                'database_filename' => $name,
                'confirmation' => 'RESTORE '.$name,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.type', 'restore');

        $restore = BackupOperation::query()->where('type', 'restore')->firstOrFail();
        $this->assertSame($name, $restore->artifacts['database']['name']);
        $this->assertSame(
            hash('sha256', 'a coherent dump'),
            $restore->artifacts['database']['sha256'],
        );
        $this->assertSame($manifest->id, $restore->metadata['manifest_operation_id']);
    }

    public function test_the_active_lock_index_refuses_a_second_active_operation(): void
    {
        $admin = $this->systemAdmin();
        BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'backup',
            'status' => 'running',
            'active_lock' => 'ogami-backup-recovery',
        ]);

        // Admission cannot be a check-then-insert race: the database itself has
        // to be the arbiter.
        $this->expectException(QueryException::class);
        BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'restore',
            'status' => 'queued',
            'active_lock' => 'ogami-backup-recovery',
        ]);
    }

    public function test_reconcile_fails_a_running_operation_whose_lease_expired(): void
    {
        $admin = $this->systemAdmin();
        $operation = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'backup',
            'status' => 'running',
            'active_lock' => 'ogami-backup-recovery',
            'started_at' => now()->subHours(3),
            'heartbeat_at' => now()->subHours(3),
            // Expired one minute ago. The old sweep compared this against
            // now() - lease_seconds, so it waited a second full lease period.
            'lease_expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(1, app(BackupService::class)->reconcileStaleOperations());

        $operation->refresh();
        $this->assertSame('failed', $operation->status);
        $this->assertNull($operation->active_lock);

        // The surface is usable again immediately.
        Queue::fake();
        $this->actingAs($admin)->postJson('/api/v1/admin/backups')->assertStatus(202);
    }

    public function test_reconcile_leaves_an_operation_with_a_live_lease_alone(): void
    {
        $admin = $this->systemAdmin();
        $operation = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'backup',
            'status' => 'running',
            'active_lock' => 'ogami-backup-recovery',
            'started_at' => now()->subMinutes(5),
            'heartbeat_at' => now()->subMinute(),
            'lease_expires_at' => now()->addHours(2),
        ]);

        $this->assertSame(0, app(BackupService::class)->reconcileStaleOperations());
        $this->assertSame('running', $operation->refresh()->status);
    }

    public function test_reconcile_marks_a_destructive_restore_as_rollback_required(): void
    {
        $admin = $this->systemAdmin();
        $operation = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'restore',
            'status' => 'running',
            'active_lock' => 'ogami-backup-recovery',
            'metadata' => ['restore_phase' => 'database_restored'],
            'started_at' => now()->subHours(3),
            'lease_expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(1, app(BackupService::class)->reconcileStaleOperations());

        $operation->refresh();
        $this->assertSame('rollback_required', $operation->status);
        $this->assertSame('ogami-backup-recovery', $operation->active_lock);
        $this->assertTrue($operation->metadata['rollback_required']);
    }

    public function test_reconcile_does_not_touch_a_preflight_failure_as_destructive(): void
    {
        $admin = $this->systemAdmin();
        $operation = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'restore',
            'status' => 'running',
            'active_lock' => 'ogami-backup-recovery',
            'metadata' => ['restore_phase' => 'preflight_complete'],
            'started_at' => now()->subHours(3),
            'lease_expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(1, app(BackupService::class)->reconcileStaleOperations());

        $operation->refresh();
        $this->assertSame('failed', $operation->status);
        $this->assertNull($operation->active_lock);
    }

    public function test_a_restore_retires_the_active_operation_row_it_imported(): void
    {
        $admin = $this->systemAdmin();

        // Every artifact contains the ledger row of the operation that produced
        // it, captured mid-run with the singleton lock held. Restoring it
        // re-imports that row, which used to block every later operation.
        $imported = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'backup',
            'status' => 'running',
            'active_lock' => 'ogami-backup-recovery',
            'started_at' => now()->subDays(2),
        ]);
        $current = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'restore',
            'status' => 'running',
        ]);

        $retired = app(BackupService::class)->retireImportedActiveOperations($current->id);

        $this->assertSame([$imported->id], $retired);
        $imported->refresh();
        $this->assertSame('failed', $imported->status);
        $this->assertNull($imported->active_lock);
        $this->assertSame('running', $current->refresh()->status);
    }

    public function test_a_rollback_required_row_is_never_retired_by_a_restore(): void
    {
        $admin = $this->systemAdmin();
        $unresolved = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'restore',
            'status' => 'rollback_required',
            'active_lock' => 'ogami-backup-recovery',
        ]);
        $current = BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'restore',
            'status' => 'running',
        ]);

        $this->assertSame([], app(BackupService::class)->retireImportedActiveOperations($current->id));
        $this->assertSame('rollback_required', $unresolved->refresh()->status);
    }

    public function test_the_recovery_catalog_pages_with_a_cursor(): void
    {
        $admin = $this->systemAdmin();
        for ($i = 0; $i < 12; $i++) {
            BackupOperation::create([
                'id' => (string) Str::uuid(),
                'requested_by' => $admin->id,
                'type' => 'backup',
                'status' => 'failed',
            ])->forceFill(['created_at' => now()->subMinutes(60 - $i)])->save();
        }

        $first = $this->actingAs($admin)
            ->getJson('/api/v1/admin/backups?per_page=5')
            ->assertOk()
            ->json('data');

        $this->assertCount(5, $first['backups']);
        $this->assertNotNull($first['next_cursor']);

        $second = $this->actingAs($admin)
            ->getJson('/api/v1/admin/backups?per_page=5&cursor='.urlencode((string) $first['next_cursor']))
            ->assertOk()
            ->json('data');

        $this->assertCount(5, $second['backups']);
        $firstIds = array_column($first['backups'], 'id');
        foreach (array_column($second['backups'], 'id') as $id) {
            $this->assertNotContains($id, $firstIds);
        }
    }

    public function test_a_legacy_local_artifact_is_listed_but_not_restorable(): void
    {
        $admin = $this->systemAdmin();
        $name = 'ogami-20260101-000000.sql.gz';
        $this->writeArtifact($name, 'a cron dump with no ledger row');

        $data = $this->actingAs($admin)
            ->getJson('/api/v1/admin/backups')
            ->assertOk()
            ->json('data');

        $legacy = collect($data['backups'])->firstWhere('status', 'available');
        $this->assertNotNull($legacy, 'the legacy artifact should be discoverable');
        $this->assertNull($legacy['id']);
        $this->assertFalse($legacy['restorable']);
        $this->assertFalse($legacy['manifest_committed']);
        // No manifest means nothing to compare a hash against, so the catalog
        // must not imply it verified one.
        $this->assertNull($legacy['artifacts']['database']['sha256']);
    }

    public function test_the_catalog_reports_a_manifest_size_mismatch_as_unrestorable(): void
    {
        $admin = $this->systemAdmin();
        $name = 'ogami-20260825-104500.sql.gz';
        $this->writeArtifact($name, 'original bytes');
        $this->completedBackup($admin, $name);
        $this->writeArtifact($name, 'shorter');

        $data = $this->actingAs($admin)
            ->getJson('/api/v1/admin/backups')
            ->assertOk()
            ->json('data');

        $operation = collect($data['backups'])->firstWhere('status', 'completed');
        $this->assertSame('mismatch', $operation['artifacts']['database']['availability']);
        $this->assertSame('mismatch', $operation['artifacts']['database']['integrity']);
        $this->assertFalse($operation['restorable']);
    }

    private function writeArtifact(string $name, string $contents): void
    {
        file_put_contents($this->backupDirectory.DIRECTORY_SEPARATOR.$name, $contents);
    }

    private function completedBackup(User $admin, string $databaseName): BackupOperation
    {
        $path = $this->backupDirectory.DIRECTORY_SEPARATOR.$databaseName;

        return BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'backup',
            'status' => 'completed',
            'artifacts' => [
                'database' => [
                    'name' => $databaseName,
                    'kind' => 'database',
                    'size' => filesize($path),
                    'sha256' => hash_file('sha256', $path),
                    'created_at' => now()->toIso8601String(),
                    'source' => 'local',
                ],
                'files' => null,
            ],
            'metadata' => ['manifest_committed' => true, 'manifest_version' => 1],
            'completed_at' => now(),
        ]);
    }

    private function systemAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email' => 'admin+'.uniqid().'@test.local',
        ]);
    }
}
