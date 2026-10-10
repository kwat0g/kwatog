<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollUnclassifiedDeductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_unclassified_other_deductions_block_approval_without_writes(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $period = PayrollPeriod::factory()->create(['status' => 'computed']);
        $employee = Employee::factory()->create([
            'date_hired' => \Illuminate\Support\Carbon::parse($period->period_start)->subDay()->toDateString(),
        ]);
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'gross_pay' => '10000.00',
            'total_deductions' => '1000.00',
            'other_deductions' => '25.00',
            'net_pay' => '9000.00',
        ]);

        $this->actingAs($finance)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Cannot approve: 1 payroll row(s) have unclassified other deductions. Resolve their treatment before continuing.',
            );

        $period->refresh();
        $this->assertSame('computed', $period->status->value);
        $this->assertNull($period->approved_by);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'payroll.period.approve',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
        ]);
    }

    public function test_unclassified_other_deductions_block_legacy_finalize_without_side_effects(): void
    {
        $approver = $this->userWithRole('finance_officer');
        $finalizer = $this->userWithRole('finance_officer');
        $period = PayrollPeriod::factory()->create(['status' => 'approved']);
        $period->forceFill(['approved_by' => $approver->id, 'approved_at' => now()])->save();
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'gross_pay' => '10000.00',
            'total_deductions' => '1000.00',
            'other_deductions' => '25.00',
            'net_pay' => '9000.00',
        ]);

        $this->actingAs($finalizer)
            ->patchJson("/api/v1/payroll-periods/{$period->hash_id}/finalize")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Cannot finalize: 1 payroll row(s) have unclassified other deductions. Resolve their treatment before continuing.',
            );

        $period->refresh();
        $this->assertSame('approved', $period->status->value);
        $this->assertNull($period->finalized_by);
        $this->assertNull($period->finalized_at);
        $this->assertSame(0, $period->bankFileRecords()->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'payroll.period.finalize',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
        ]);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }
}
