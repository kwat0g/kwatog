<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Jobs\CreateBackupJob;
use App\Common\Models\BackupOperation;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_backup_history_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/backups')->assertUnauthorized();
    }

    public function test_backup_history_requires_backup_permission(): void
    {
        $user = $this->userWithPermissions(['hr.employees.view']);

        $this->actingAs($user)
            ->getJson('/api/v1/admin/backups')
            ->assertForbidden();
    }

    public function test_system_admin_can_queue_a_full_backup(): void
    {
        Queue::fake();
        $admin = $this->systemAdmin();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/backups')
            ->assertStatus(202)
            ->assertJsonPath('data.type', 'backup')
            ->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseHas('backup_operations', [
            'requested_by' => $admin->id,
            'type' => 'backup',
            'status' => 'queued',
        ]);
        Queue::assertPushed(CreateBackupJob::class);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/backups')
            ->assertOk()
            ->assertJsonPath('data.backups.0.artifacts.database', null)
            ->assertJsonPath('data.backups.0.artifacts.files', null);
    }

    public function test_backup_and_restore_operations_cannot_overlap(): void
    {
        Queue::fake();
        $admin = $this->systemAdmin();

        BackupOperation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $admin->id,
            'type' => 'restore',
            'status' => 'queued',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/backups')
            ->assertStatus(422)
            ->assertJsonValidationErrors('operation');
        Queue::assertNothingPushed();
    }

    public function test_restore_requires_the_typed_confirmation_phrase(): void
    {
        $admin = $this->systemAdmin();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/backups/restore', [
                'database_filename' => 'ogami-20260822-120000.sql.gz',
                'confirmation' => 'yes',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_restore_rejects_path_traversal_artifact_names(): void
    {
        $admin = $this->systemAdmin();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/backups/restore', [
                'database_filename' => '../.env',
                'confirmation' => 'RESTORE ../.env',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('database_filename');
    }

    private function systemAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email' => 'admin+'.uniqid().'@test.local',
        ]);
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Backup test role',
            'slug' => 'backup_test_'.uniqid(),
            'description' => 'Test role',
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id')->all());

        return User::factory()->create([
            'role_id' => $role->id,
            'email' => 'user+'.uniqid().'@test.local',
        ]);
    }
}
