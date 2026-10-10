<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\SeparationService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearanceReadScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DepartmentSeeder::class]);
    }

    public function test_department_reader_cannot_read_or_sign_other_department_records(): void
    {
        $own = $this->clearance('PROD');
        $other = $this->clearance('QC');
        $head = $this->actor('department_head', 'PROD');
        $this->actingAs($head)->getJson('/api/v1/hr/clearances')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->hash_id);
        $this->getJson("/api/v1/hr/clearances/{$other->hash_id}")->assertNotFound();
        $head->role->permissions()->detach(\App\Modules\Auth\Models\Permission::where('slug', 'hr.employees.view')->value('id'));
        $head->flushPermissionsCache();
        $this->getJson('/api/v1/hr/clearances')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/hr/clearances/{$other->hash_id}")->assertNotFound();
        $this->patchJson("/api/v1/hr/clearances/{$other->hash_id}/items", ['item_key' => 'tools_returned'])->assertNotFound();
        $this->getJson("/api/v1/hr/clearances/{$own->hash_id}")->assertOk()
            ->assertJsonCount(2, 'data.clearance_items')->assertJsonPath('data.final_pay_amount', null)
            ->assertJsonPath('data.final_pay_breakdown', null)->assertJsonPath('data.journal_entry', null);
    }

    public function test_operational_signers_see_only_applicable_checklists_without_financial_details(): void
    {
        $clearance = $this->clearance('PROD');
        $hidden = $this->clearance('QC');
        $hidden->update(['clearance_items' => [['department' => 'Finance', 'item_key' => 'finance_only', 'status' => 'pending']]]);
        foreach (['warehouse_staff' => ['WH', 'materials_returned'], 'maintenance_tech' => ['MAINT', 'no_pending_work']] as $role => [$department, $item]) {
            $this->actingAs($this->actor($role, $department))->getJson('/api/v1/hr/clearances')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $clearance->hash_id);
            $this->getJson("/api/v1/hr/clearances/{$hidden->hash_id}")->assertNotFound();
            $this->getJson("/api/v1/hr/clearances/{$clearance->hash_id}")->assertOk()
                ->assertJsonCount(1, 'data.clearance_items')->assertJsonPath('data.clearance_items.0.item_key', $item)
                ->assertJsonPath('data.clearance_items.0.can_sign', true)->assertJsonPath('data.can_view_final_pay', false)
                ->assertJsonPath('data.final_pay_amount', null)->assertJsonPath('data.final_pay_breakdown', null)
                ->assertJsonPath('data.employee.pay_type', null)->assertJsonPath('data.employee.date_hired', null);
            $this->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/items", ['item_key' => $item])->assertOk()
                ->assertJsonCount(1, 'data.clearance_items')->assertJsonPath('data.final_pay_amount', null);
        }
    }

    public function test_hr_and_finance_retain_full_clearance_and_financial_review(): void
    {
        $clearance = $this->clearance('PROD');
        foreach (['hr_officer' => 'HR', 'finance_officer' => 'FIN'] as $role => $department) {
            $this->actingAs($this->actor($role, $department))->getJson("/api/v1/hr/clearances/{$clearance->hash_id}")
                ->assertOk()->assertJsonCount(11, 'data.clearance_items')->assertJsonPath('data.can_view_final_pay', true)
                ->assertJsonPath('data.final_pay_amount', '12345.67');
        }
    }

    public function test_unlinked_signer_fails_closed_and_self_service_roles_are_denied(): void
    {
        $clearance = $this->clearance('PROD');
        $user = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $this->actingAs($user)->getJson('/api/v1/hr/clearances')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/hr/clearances/{$clearance->hash_id}")->assertNotFound();
        foreach (['employee', 'driver', 'sales_officer', 'ppc_head', 'qc_inspector'] as $role) {
            $this->actingAs($this->actor($role, 'PROD'))->getJson('/api/v1/hr/clearances')->assertForbidden();
        }
    }

    private function actor(string $role, string $department): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => Department::where('code', $department)->value('id')])->id,
        ]);
    }

    private function clearance(string $department): Clearance
    {
        return Clearance::factory()->create([
            'employee_id' => Employee::factory()->create(['department_id' => Department::where('code', $department)->value('id')])->id,
            'status' => 'in_progress', 'final_pay_computed' => true, 'final_pay_amount' => '12345.67',
            'final_pay_breakdown' => ['basic_pay' => '12345.67'],
            'clearance_items' => array_map(fn ($item) => array_merge($item, ['status' => 'pending']), SeparationService::defaultChecklist()),
        ]);
    }
}
