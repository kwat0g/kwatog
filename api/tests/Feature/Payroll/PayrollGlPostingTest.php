<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollGlPostingService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\PayrollChartAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollGlPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->seed(PayrollChartAccountsSeeder::class);
    }

    private function fullySetup(): array
    {
        $roleId = Role::query()->orderBy('id')->value('id');
        $user = User::create([
            'name'     => 'Tester '.uniqid(),
            'email'    => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);

        $dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        $emp = Employee::create([
            'employee_no' => 'OGM-2026-0001',
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality' => 'Filipino',
            'street_address' => '123 Main', 'city' => 'Dasmariñas', 'province' => 'Cavite',
            'mobile_number' => '09171234567', 'email' => 'jdc@example.com',
            'emergency_contact_name' => 'Maria', 'emergency_contact_phone' => '09181234567',
            'department_id' => $dept->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly',
            'date_hired' => '2025-01-01', 'basic_monthly_salary' => '20000.00',
            'status' => 'active',
        ]);

        $period = PayrollPeriod::create([
            'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15', 'is_first_half' => true,
            'is_thirteenth_month' => false,
            'status' => PayrollPeriodStatus::Draft->value,
            'created_by' => $user->id,
        ]);

        // Single attendance row, 8h regular work day for simplicity.
        \App\Modules\Attendance\Models\Attendance::create([
            'employee_id' => $emp->id, 'date' => '2026-04-01',
            'time_in' => '2026-04-01 08:00:00', 'time_out' => '2026-04-01 17:00:00',
            'regular_hours' => 8, 'overtime_hours' => 0, 'night_diff_hours' => 0,
            'tardiness_minutes' => 0, 'undertime_minutes' => 0,
            'is_rest_day' => false, 'day_type_rate' => 1.00, 'status' => 'present',
        ]);

        app(PayrollCalculatorService::class)->computeForEmployee($period, $emp);
        $period->update(['status' => PayrollPeriodStatus::Approved->value]);
        $period->update(['status' => PayrollPeriodStatus::Finalized->value]);

        return [$user, $period];
    }

    public function test_balanced_journal_entry_created_when_accounting_enabled(): void
    {
        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        [, $period] = $this->fullySetup();

        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());

        $this->assertNotNull($entryId);
        $entry = DB::table('journal_entries')->where('id', $entryId)->first();
        $this->assertSame((string) $entry->total_debit, (string) $entry->total_credit, 'Journal entry must balance');
        $this->assertSame('posted', $entry->status);
        $this->assertSame('payroll_period', $entry->reference_type);
        $this->assertSame((int) $period->id, (int) $entry->reference_id);

        $period->refresh();
        $this->assertSame((int) $entryId, (int) $period->journal_entry_id);
    }

    public function test_idempotent_returns_existing_entry(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        [, $period] = $this->fullySetup();
        $service = app(PayrollGlPostingService::class);

        $first  = $service->post($period->fresh());
        $second = $service->post($period->fresh());

        $this->assertSame((int) $first, (int) $second);
        $this->assertSame(1, DB::table('journal_entries')->where('reference_id', $period->id)->count());
    }

    public function test_skips_when_accounting_module_disabled(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', false, 'modules');

        [, $period] = $this->fullySetup();
        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());

        $this->assertNull($entryId);
        $this->assertNull($period->fresh()->journal_entry_id);
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_only_finalized_period_can_post(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        $roleId = Role::query()->orderBy('id')->value('id');
        $user = User::create(['name' => 'X', 'email' => 'x_'.uniqid().'@x.test', 'password' => bcrypt('p'), 'role_id' => $roleId]);
        $period = PayrollPeriod::create([
            'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15', 'is_first_half' => true,
            'status' => PayrollPeriodStatus::Draft->value,
            'created_by' => $user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        app(PayrollGlPostingService::class)->post($period);
    }
}
