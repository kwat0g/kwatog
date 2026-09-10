<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Models\EmployeeLoan;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LN-01 — the seeded company_loan chain is department_head →
 * production_manager → finance_officer → system_admin, but
 * production_manager held no loans permission and the row scope never
 * matched chain participants, so every submitted company loan stalled at
 * step 2: invisible on the approval queue and unactionable through the
 * approve route. Mirrors the M036 fix tests for the purchase_request chain.
 */
class LoanApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    private Department $deptA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
        ]);
        $this->deptA = Department::query()->orderBy('id')->first();
    }

    private function user(string $roleSlug, ?int $departmentId = null): User
    {
        $attributes = ['role_id' => Role::query()->where('slug', $roleSlug)->value('id')];
        if ($departmentId !== null) {
            $attributes['employee_id'] = Employee::factory()->create(['department_id' => $departmentId])->id;
        }

        return User::factory()->create($attributes);
    }

    private function pendingLoan(): EmployeeLoan
    {
        $borrower = Employee::factory()->create(['department_id' => $this->deptA->id]);

        return EmployeeLoan::factory()->pending()->create(['employee_id' => $borrower->id]);
    }

    /** @param array<int, array{role: string, action?: string}> $steps */
    private function seedChain(EmployeeLoan $loan, array $steps): void
    {
        $order = 0;
        foreach ($steps as $step) {
            $order++;
            DB::table('approval_records')->insert([
                'approvable_type' => $loan->getMorphClass(),
                'approvable_id'   => $loan->id,
                'step_order'      => $order,
                'role_slug'       => $step['role'],
                'action'          => $step['action'] ?? 'pending',
                'is_current'      => true,
                'created_at'      => now()->subHour(),
            ]);
        }
    }

    private function approve(EmployeeLoan $loan, User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/loans/'.$loan->hash_id.'/approve', ['remarks' => 'ok']);
    }

    private function stepAction(EmployeeLoan $loan, int $order): ?string
    {
        return DB::table('approval_records')
            ->where('approvable_type', $loan->getMorphClass())
            ->where('approvable_id', $loan->id)
            ->where('step_order', $order)
            ->value('action');
    }

    public function test_production_manager_sees_the_pending_loan_in_the_list(): void
    {
        $loan = $this->pendingLoan();
        $this->seedChain($loan, [
            ['role' => 'department_head', 'action' => 'approved'],
            ['role' => 'production_manager'],
        ]);
        $manager = $this->user('production_manager');

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/loans?per_page=100')
            ->assertOk();

        $numbers = array_map(static fn (array $row): string => (string) $row['loan_no'], $response->json('data'));
        $this->assertContains($loan->loan_no, $numbers);
    }

    public function test_production_manager_can_approve_step_two(): void
    {
        $loan = $this->pendingLoan();
        $this->seedChain($loan, [
            ['role' => 'department_head', 'action' => 'approved'],
            ['role' => 'production_manager'],
            ['role' => 'finance_officer'],
            ['role' => 'system_admin'],
        ]);
        $manager = $this->user('production_manager');

        $this->approve($loan, $manager)->assertOk();

        $this->assertSame('approved', $this->stepAction($loan, 2));
        $this->assertSame(LoanStatus::Pending, $loan->fresh()->status);
    }

    public function test_full_company_loan_chain_completes_end_to_end(): void
    {
        $loan = $this->pendingLoan();
        $this->seedChain($loan, [
            ['role' => 'department_head'],
            ['role' => 'production_manager'],
            ['role' => 'finance_officer'],
            ['role' => 'system_admin'],
        ]);

        $head = $this->user('department_head', $this->deptA->id);
        $manager = $this->user('production_manager');
        $finance = $this->user('finance_officer');
        $vp = $this->user('system_admin');

        $this->approve($loan, $head)->assertOk();
        $this->assertSame('approved', $this->stepAction($loan, 1));
        $this->assertSame(LoanStatus::Pending, $loan->fresh()->status);

        $this->approve($loan, $manager)->assertOk();
        $this->assertSame('approved', $this->stepAction($loan, 2));
        $this->assertSame(LoanStatus::Pending, $loan->fresh()->status);

        $this->approve($loan, $finance)->assertOk();
        $this->assertSame('approved', $this->stepAction($loan, 3));
        $this->assertSame(LoanStatus::Pending, $loan->fresh()->status);

        $this->approve($loan, $vp)->assertOk();
        $this->assertSame('approved', $this->stepAction($loan, 4));

        $fresh = $loan->fresh();
        $this->assertSame(LoanStatus::Active, $fresh->status);
        $this->assertNotNull($fresh->start_date);
    }

    public function test_role_without_the_approve_permission_is_rejected(): void
    {
        $loan = $this->pendingLoan();
        $this->seedChain($loan, [
            ['role' => 'department_head', 'action' => 'approved'],
            ['role' => 'production_manager'],
            ['role' => 'finance_officer'],
            ['role' => 'system_admin'],
        ]);
        $warehouse = $this->user('warehouse_staff');

        $this->approve($loan, $warehouse)->assertForbidden();

        $this->assertSame('pending', $this->stepAction($loan, 2));
    }

    public function test_approve_permission_holder_from_another_step_is_rejected(): void
    {
        $loan = $this->pendingLoan();
        $this->seedChain($loan, [
            ['role' => 'department_head', 'action' => 'approved'],
            ['role' => 'production_manager'],
        ]);
        // department_head holds loans.approve but its step (1) is already
        // done; the pending step belongs to production_manager alone.
        $head = $this->user('department_head', $this->deptA->id);

        $this->approve($loan, $head)->assertForbidden();

        $this->assertSame('pending', $this->stepAction($loan, 2));
    }
}
