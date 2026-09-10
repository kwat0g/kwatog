<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\SeparationService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * HR-03 — clearance signing is gated per department.
 *
 * The flat hr.clearance.sign permission only answers whether a role signs
 * clearance items at all; SeparationService::signItem() additionally requires
 * the signer's employee to belong to the department that owns the item.
 * hr_officer and system_admin remain fallback signers for every department.
 */
class ClearanceDepartmentGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Settings are cached in Redis, which RefreshDatabase does not reset,
        // so an earlier test's checklist could otherwise be served to this one.
        Cache::flush();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentSeeder::class);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function makeSigner(string $roleSlug, string $departmentCode): User
    {
        $department = Department::query()->where('code', $departmentCode)->firstOrFail();

        return User::factory()->create([
            'role_id'     => Role::query()->where('slug', $roleSlug)->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
    }

    private function makeClearanceWithSeededChecklist(): Clearance
    {
        $items = array_map(fn (array $row): array => [
            'department' => $row['department'],
            'item_key'   => $row['item_key'],
            'label'      => $row['label'],
            'status'     => 'pending',
            'signed_by'  => null,
            'signed_at'  => null,
            'remarks'    => null,
        ], SeparationService::defaultChecklist());

        return Clearance::factory()->create([
            'clearance_items' => $items,
            'status'          => ClearanceStatus::InProgress->value,
        ]);
    }

    private function sign(User $actor, Clearance $clearance, string $itemKey): TestResponse
    {
        return $this->actingAs($actor)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/items", ['item_key' => $itemKey]);
    }

    private function itemStatus(Clearance $clearance, string $itemKey): ?string
    {
        $item = collect($clearance->fresh()->clearance_items)->firstWhere('item_key', $itemKey);

        return $item['status'] ?? null;
    }

    // ─────────────────────────────────────────────────────────────
    // 1. Refused — department head signing another department's item
    // ─────────────────────────────────────────────────────────────

    public function test_department_head_cannot_sign_another_departments_item(): void
    {
        $clearance = $this->makeClearanceWithSeededChecklist();
        $productionHead = $this->makeSigner('department_head', 'PROD');

        $this->sign($productionHead, $clearance, 'no_outstanding_loan')
            ->assertStatus(422)
            ->assertJsonPath('errors.error.0', fn (string $m) => str_contains($m, 'belongs to the Finance department'));

        $this->assertSame('pending', $this->itemStatus($clearance, 'no_outstanding_loan'));

        $this->sign($productionHead, $clearance, 'accounts_disabled')->assertStatus(422);
        $this->assertSame('pending', $this->itemStatus($clearance, 'accounts_disabled'));
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Allowed — department head signing their own department's item
    // ─────────────────────────────────────────────────────────────

    public function test_department_head_can_sign_their_own_departments_item(): void
    {
        $clearance = $this->makeClearanceWithSeededChecklist();
        $productionHead = $this->makeSigner('department_head', 'PROD');

        $this->sign($productionHead, $clearance, 'tools_returned')->assertOk();

        $this->assertSame('cleared', $this->itemStatus($clearance, 'tools_returned'));
    }

    // ─────────────────────────────────────────────────────────────
    // 3. Allowed — finance officer signs the Finance items
    // ─────────────────────────────────────────────────────────────

    public function test_finance_officer_can_sign_the_finance_items(): void
    {
        $clearance = $this->makeClearanceWithSeededChecklist();
        $finance = $this->makeSigner('finance_officer', 'FIN');

        $this->sign($finance, $clearance, 'no_outstanding_loan')->assertOk();
        $this->sign($finance, $clearance, 'no_outstanding_ca')->assertOk();

        $this->assertSame('cleared', $this->itemStatus($clearance, 'no_outstanding_loan'));
        $this->assertSame('cleared', $this->itemStatus($clearance, 'no_outstanding_ca'));

        // The same officer cannot step outside Finance.
        $this->sign($finance, $clearance, 'tools_returned')->assertStatus(422);
        $this->assertSame('pending', $this->itemStatus($clearance, 'tools_returned'));
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Allowed — warehouse and maintenance sign their own items
    // ─────────────────────────────────────────────────────────────

    public function test_warehouse_and_maintenance_can_sign_their_own_items(): void
    {
        $clearance = $this->makeClearanceWithSeededChecklist();
        $warehouse = $this->makeSigner('warehouse_staff', 'WH');
        $maintenance = $this->makeSigner('maintenance_tech', 'MAINT');

        $this->sign($warehouse, $clearance, 'materials_returned')->assertOk();
        $this->sign($maintenance, $clearance, 'no_pending_work')->assertOk();

        $this->assertSame('cleared', $this->itemStatus($clearance, 'materials_returned'));
        $this->assertSame('cleared', $this->itemStatus($clearance, 'no_pending_work'));
    }

    // ─────────────────────────────────────────────────────────────
    // 5. Allowed — hr_officer / system_admin sign any department's item
    // ─────────────────────────────────────────────────────────────

    public function test_hr_officer_and_system_admin_can_sign_any_item(): void
    {
        $clearance = $this->makeClearanceWithSeededChecklist();
        $hr = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
        ]);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->sign($hr, $clearance, 'accounts_disabled')->assertOk();
        $this->sign($admin, $clearance, 'equipment_returned')->assertOk();

        $this->assertSame('cleared', $this->itemStatus($clearance, 'accounts_disabled'));
        $this->assertSame('cleared', $this->itemStatus($clearance, 'equipment_returned'));
    }

    // ─────────────────────────────────────────────────────────────
    // 6. Refused — signer without an employee record
    // ─────────────────────────────────────────────────────────────

    public function test_signer_without_an_employee_record_cannot_sign_any_item(): void
    {
        $clearance = $this->makeClearanceWithSeededChecklist();
        $rolelessHead = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
        ]);

        $this->sign($rolelessHead, $clearance, 'tools_returned')->assertStatus(422);

        $this->assertSame('pending', $this->itemStatus($clearance, 'tools_returned'));
    }

    // ─────────────────────────────────────────────────────────────
    // 7. Every seeded checklist item resolves to exactly one department
    // ─────────────────────────────────────────────────────────────

    public function test_every_seeded_checklist_item_resolves_to_exactly_one_department(): void
    {
        $expected = [
            'tools_returned'      => 'PROD',
            'ppe_returned'        => 'PROD',
            'materials_returned'  => 'WH',
            'no_pending_work'     => 'MAINT',
            'no_outstanding_ca'   => 'FIN',
            'no_outstanding_loan' => 'FIN',
            'id_returned'         => 'HR',
            'file_201_complete'   => 'HR',
            'exit_interview_done' => 'HR',
            'equipment_returned'  => 'IT',
            'accounts_disabled'   => 'IT',
        ];

        $checklist = SeparationService::defaultChecklist();
        $this->assertCount(11, $checklist);

        $service = app(SeparationService::class);
        foreach ($checklist as $item) {
            $department = $service->resolveChecklistDepartment($item['department']);

            $this->assertNotNull(
                $department,
                "Checklist item '{$item['item_key']}' ({$item['department']}) resolved to no department."
            );
            $this->assertSame(
                $expected[$item['item_key']],
                $department->code,
                "Checklist item '{$item['item_key']}' resolved to {$department->code}."
            );
        }
    }
}
