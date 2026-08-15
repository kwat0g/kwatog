<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\FinalPayService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audit untraced list — P05 advisory: FinalPayService::compute accepts no
 * actor and records none, so the breakdown that fixes every figure the JE
 * later spends has no author (while finalize is attributed). Compute must
 * record who computed the breakdown.
 */
class FinalPayComputeAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeEmployee(): Employee
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $pos  = Position::firstOrCreate(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::create([
            'employee_no'          => 'OGM-'.str_pad((string) random_int(1, 99999), 4, '0', STR_PAD_LEFT),
            'first_name'           => 'Juan',
            'last_name'            => 'Cruz',
            'birth_date'           => '1990-01-01',
            'gender'               => 'male',
            'civil_status'         => 'single',
            'nationality'          => 'Filipino',
            'department_id'        => $dept->id,
            'position_id'          => $pos->id,
            'employment_type'      => 'regular',
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => '20000.00',
            'date_hired'           => '2024-01-01',
            'status'               => 'active',
        ]);
    }

    private function makeClearance(Employee $employee): Clearance
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user = User::factory()->create(['role_id' => $role->id]);

        return Clearance::create([
            'clearance_no'      => 'CLR-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'employee_id'       => $employee->id,
            'separation_date'   => '2026-05-31',
            'separation_reason' => SeparationReason::Resigned->value,
            'clearance_items'   => [],
            'status'            => ClearanceStatus::Completed->value,
            'initiated_by'      => $user->id,
        ]);
    }

    /** An open (non-disbursed) payroll period covering the separation date. */
    private function seedOpenPayrollPeriod(): void
    {
        DB::table('payroll_periods')->insert([
            'period_start'        => '2026-05-16',
            'period_end'          => '2026-05-31',
            'payroll_date'        => '2026-06-05',
            'is_first_half'       => false,
            'is_thirteenth_month' => false,
            'status'              => 'draft',
            'created_by'          => User::query()->firstOrFail()->id,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    /** One 8-hour worked day inside the final period → 20000/22 ≈ 909.09. */
    private function seedAttendanceDay(Employee $employee): void
    {
        DB::table('attendances')->insert([
            'employee_id'   => $employee->id,
            'date'          => '2026-05-16',
            'regular_hours' => 8.0,
            'status'        => 'present',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function test_compute_records_who_computed_the_final_pay_breakdown(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);

        $actor    = User::factory()->create();
        $computed = app(FinalPayService::class)->compute($clearance, $actor);

        $this->assertTrue($computed->final_pay_computed);

        $audit = AuditLog::query()
            ->where('action', 'hr.clearance.final_pay_computed')
            ->where('model_type', Clearance::class)
            ->where('model_id', $clearance->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'Final-pay compute must be attributed to an actor.');
        $this->assertSame($actor->id, $audit->user_id);
    }
}
