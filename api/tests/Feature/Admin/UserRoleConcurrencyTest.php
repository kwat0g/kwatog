<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_single_role_change_rejects_stale_expected_role_without_overwrite(): void
    {
        $admin = $this->admin();
        $employeeRole = Role::where('slug', 'employee')->firstOrFail();
        $hrRole = Role::where('slug', 'hr_officer')->firstOrFail();
        $financeRole = Role::where('slug', 'finance_officer')->firstOrFail();
        $target = User::factory()->create(['role_id' => $employeeRole->id]);

        $target->update(['role_id' => $financeRole->id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$target->hash_id}/role", [
                'role_id' => $hrRole->hash_id,
                'expected_role_id' => $employeeRole->hash_id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'changed'));

        $this->assertSame($financeRole->id, $target->fresh()->role_id);
        $this->assertDatabaseMissing('audit_logs', [
            'model_type' => $target->getMorphClass(),
            'model_id' => $target->id,
            'action' => 'role_changed',
        ]);
    }

    public function test_single_role_change_uses_expected_role_and_audits_old_new_reason(): void
    {
        $admin = $this->admin();
        $from = Role::where('slug', 'employee')->firstOrFail();
        $to = Role::where('slug', 'hr_officer')->firstOrFail();
        $target = User::factory()->create(['role_id' => $from->id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$target->hash_id}/role", [
                'role_id' => $to->hash_id,
                'expected_role_id' => $from->hash_id,
                'reason' => 'Least privilege review',
            ])
            ->assertOk()
            ->assertJsonPath('data.role.slug', 'hr_officer');

        $this->assertSame($to->id, $target->fresh()->role_id);
        $audit = AuditLog::query()
            ->where('model_type', $target->getMorphClass())
            ->where('model_id', $target->id)
            ->where('action', 'role_changed')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($from->id, $audit->old_values['role_id']);
        $this->assertSame($to->id, $audit->new_values['role_id']);
        $this->assertSame('Least privilege review', $audit->new_values['reason']);
    }

    public function test_bulk_role_change_skips_stale_users_and_reports_conflicts(): void
    {
        $admin = $this->admin();
        $from = Role::where('slug', 'employee')->firstOrFail();
        $changed = Role::where('slug', 'finance_officer')->firstOrFail();
        $to = Role::where('slug', 'hr_officer')->firstOrFail();
        $fresh = User::factory()->create(['role_id' => $from->id]);
        $stale = User::factory()->create(['role_id' => $from->id]);
        $stale->update(['role_id' => $changed->id]);

        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/users/bulk-role', [
                'user_ids' => [$fresh->hash_id, $stale->hash_id],
                'role_id' => $to->hash_id,
                'reason' => 'Quarterly access review',
                'expected_role_ids' => [
                    $fresh->hash_id => $from->hash_id,
                    $stale->hash_id => $from->hash_id,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonCount(1, 'data.conflicts');

        $this->assertSame($to->id, $fresh->fresh()->role_id);
        $this->assertSame($changed->id, $stale->fresh()->role_id);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }
}
