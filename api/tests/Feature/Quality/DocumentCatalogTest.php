<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\ControlledDocument;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function adminUser(): User
    {
        return User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => true,
        ]);
    }

    private function employeeUser(): User
    {
        return User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'employee')->value('id'),
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_controlled_document(): void
    {
        $admin = $this->adminUser();

        $resp = $this->actingAs($admin)->postJson('/api/v1/quality/documents', [
            'code'                   => 'SOP-QC-001',
            'title'                  => 'Outgoing AQL Inspection',
            'category'               => 'sop',
            'assignee_role'          => 'qc_inspector',
            'review_interval_months' => 12,
            'description'            => 'Procedure for outgoing AQL Level II.',
        ]);

        $resp->assertCreated()
             ->assertJsonPath('data.code', 'SOP-QC-001')
             ->assertJsonPath('data.category', 'sop')
             ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('controlled_documents', [
            'code'  => 'SOP-QC-001',
            'title' => 'Outgoing AQL Inspection',
        ]);
    }

    public function test_index_lists_documents(): void
    {
        $admin = $this->adminUser();
        ControlledDocument::create([
            'code' => 'SOP-QC-100', 'title' => 'Doc A', 'category' => 'sop',
            'assignee_role' => 'qc_inspector', 'is_active' => true,
        ]);
        ControlledDocument::create([
            'code' => 'SOP-QC-101', 'title' => 'Doc B', 'category' => 'spec',
            'assignee_role' => 'qc_inspector', 'is_active' => false,
        ]);

        $resp = $this->actingAs($admin)->getJson('/api/v1/quality/documents');
        $resp->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_show_returns_single_document(): void
    {
        $admin = $this->adminUser();
        $doc = ControlledDocument::create([
            'code' => 'SOP-QC-200', 'title' => 'Show Me', 'category' => 'sop',
            'assignee_role' => 'qc_inspector', 'is_active' => true,
        ]);

        $this->actingAs($admin)->getJson('/api/v1/quality/documents/'.$doc->hash_id)
            ->assertOk()
            ->assertJsonPath('data.id', $doc->hash_id)
            ->assertJsonPath('data.code', 'SOP-QC-200')
            ->assertJsonPath('data.title', 'Show Me');
    }

    public function test_update_can_archive_a_document(): void
    {
        $admin = $this->adminUser();
        $doc = ControlledDocument::create([
            'code' => 'SOP-QC-300', 'title' => 'To Archive', 'category' => 'sop',
            'assignee_role' => 'qc_inspector', 'is_active' => true,
        ]);

        $this->actingAs($admin)->patchJson('/api/v1/quality/documents/'.$doc->hash_id, [
            'is_active' => false,
            'title'     => 'To Archive (Renamed)',
        ])->assertOk()
          ->assertJsonPath('data.is_active', false)
          ->assertJsonPath('data.title', 'To Archive (Renamed)');
    }

    public function test_employee_without_manage_permission_cannot_create(): void
    {
        $emp = $this->employeeUser();

        $this->actingAs($emp)->postJson('/api/v1/quality/documents', [
            'code'          => 'SOP-QC-999',
            'title'         => 'Forbidden',
            'category'      => 'sop',
            'assignee_role' => 'qc_inspector',
        ])->assertForbidden();
    }
}
