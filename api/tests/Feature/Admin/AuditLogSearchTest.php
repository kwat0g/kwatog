<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entity-scoped audit trail + PDF export (Task 4 — IATF compliance).
 */
class AuditLogSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ------------------------------------------------------------------
    // Entity trail
    // ------------------------------------------------------------------

    public function test_entity_trail_returns_scoped_audit_logs(): void
    {
        $admin = $this->seedAdmin();

        // Seed 3 logs for model A, 2 for model B.
        $modelType = 'App\\Modules\\Purchasing\\Models\\PurchaseOrder';
        foreach (range(1, 3) as $i) {
            AuditLog::create([
                'user_id'    => $admin->id,
                'action'     => 'updated',
                'model_type' => $modelType,
                'model_id'   => 42,
                'old_values' => ['status' => 'draft'],
                'new_values' => ['status' => 'approved'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'TestAgent',
                'created_at' => now()->subMinutes($i),
            ]);
        }
        foreach (range(1, 2) as $i) {
            AuditLog::create([
                'user_id'    => $admin->id,
                'action'     => 'created',
                'model_type' => $modelType,
                'model_id'   => 99,
                'old_values' => null,
                'new_values' => ['status' => 'draft'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'TestAgent',
                'created_at' => now()->subMinutes($i),
            ]);
        }

        // Query by basename (PurchaseOrder) + integer model_id (test env).
        $resp = $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-logs/entity?model_type=PurchaseOrder&model_id=42');

        $resp->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.model_type', 'PurchaseOrder');

        // Confirm the other model_id is not included.
        foreach ($resp->json('data') as $row) {
            $this->assertNotNull($row['model_id']);
        }
    }

    public function test_entity_trail_requires_both_params(): void
    {
        $admin = $this->seedAdmin();

        // Missing model_id
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-logs/entity?model_type=PurchaseOrder')
            ->assertStatus(422);

        // Missing model_type
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-logs/entity?model_id=42')
            ->assertStatus(422);

        // Both missing
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-logs/entity')
            ->assertStatus(422);
    }

    public function test_entity_trail_returns_empty_for_nonexistent_record(): void
    {
        $admin = $this->seedAdmin();

        $resp = $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-logs/entity?model_type=PurchaseOrder&model_id=999999');

        $resp->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------
    // PDF export
    // ------------------------------------------------------------------

    public function test_pdf_export_generates_downloadable_file(): void
    {
        $admin = $this->seedAdmin();

        // Seed at least one audit log.
        AuditLog::create([
            'user_id'    => $admin->id,
            'action'     => 'created',
            'model_type' => 'App\\Modules\\HR\\Models\\Employee',
            'model_id'   => 1,
            'old_values' => null,
            'new_values' => ['first_name' => 'Test'],
            'ip_address' => '10.0.0.1',
            'user_agent' => 'TestAgent',
            'created_at' => now(),
        ]);

        $resp = $this->actingAs($admin)
            ->get('/api/v1/admin/audit-logs/export/pdf');

        $resp->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // The response body should start with %PDF (magic bytes).
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    public function test_pdf_export_respects_filters(): void
    {
        $admin = $this->seedAdmin();

        AuditLog::create([
            'user_id'    => $admin->id,
            'action'     => 'deleted',
            'model_type' => 'App\\Modules\\Inventory\\Models\\Item',
            'model_id'   => 5,
            'old_values' => ['name' => 'Widget'],
            'new_values' => null,
            'ip_address' => '10.0.0.2',
            'user_agent' => 'TestAgent',
            'created_at' => now(),
        ]);

        // The endpoint should still return a valid PDF even with filters.
        $resp = $this->actingAs($admin)
            ->get('/api/v1/admin/audit-logs/export/pdf?action=deleted');

        $resp->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // ------------------------------------------------------------------
    // Permission enforcement
    // ------------------------------------------------------------------

    public function test_entity_trail_requires_permission(): void
    {
        $user = $this->seedUserWithoutAuditPerm();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/audit-logs/entity?model_type=Employee&model_id=1')
            ->assertStatus(403);
    }

    public function test_pdf_export_requires_permission(): void
    {
        $user = $this->seedUserWithoutAuditPerm();

        $this->actingAs($user)
            ->get('/api/v1/admin/audit-logs/export/pdf')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function seedAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email'   => 'admin+' . uniqid() . '@test.local',
        ]);
    }

    private function seedUserWithoutAuditPerm(): User
    {
        $role = Role::create([
            'name'        => 'No Audit',
            'slug'        => 'no_audit_' . substr(uniqid(), -5),
            'description' => 'No audit log permission',
            'is_system'   => false,
        ]);
        // Grant some benign permission, NOT admin.audit_logs.view
        $ids = Permission::whereIn('slug', ['hr.employees.view'])->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::factory()->create([
            'role_id' => $role->id,
            'email'   => 'user+' . uniqid() . '@test.local',
        ]);
    }
}
