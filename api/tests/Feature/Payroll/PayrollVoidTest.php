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
use App\Modules\Payroll\Events\PayrollPeriodVoided;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollGlPostingService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\PayrollChartAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PayrollVoidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->seed(PayrollChartAccountsSeeder::class);
    }

    private function makeUser(): User
    {
        $roleId = Role::query()->orderBy('id')->value('id');
        return User::create([
            'name'     => 'Tester '.uniqid(),
            'email'    => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function finalizedPeriod(User $user, bool $postGl): PayrollPeriod
    {
        $dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        $emp = Employee::create([
            'employee_no' => 'OGM-2026-0001',
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality' => 'Filipino', 'street_address' => '123 Main',
            'city' => 'Dasmariñas', 'province' => 'Cavite',
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
            'is_thirteenth_month' => false, 'created_by' => $user->id,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

        \App\Modules\Attendance\Models\Attendance::create([
            'employee_id' => $emp->id, 'date' => '2026-04-01',
            'time_in' => '2026-04-01 08:00:00', 'time_out' => '2026-04-01 17:00:00',
            'regular_hours' => 8, 'overtime_hours' => 0, 'night_diff_hours' => 0,
            'tardiness_minutes' => 0, 'undertime_minutes' => 0,
            'is_rest_day' => false, 'day_type_rate' => 1.00, 'status' => 'present',
        ]);

        app(PayrollCalculatorService::class)->computeForEmployee($period, $emp);
        $period->forceFill(['status' => PayrollPeriodStatus::Approved->value])->save();
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        if ($postGl) {
            app(SettingsService::class)->set('modules.accounting', true, 'modules');
            app(PayrollGlPostingService::class)->post($period->fresh());
        }

        return $period->fresh();
    }

    public function test_only_finalized_period_can_be_voided(): void
    {
        $svc = app(PayrollPeriodService::class);
        $user = $this->makeUser();
        $period = PayrollPeriod::create([
            'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15', 'is_first_half' => true,
            'created_by' => $user->id,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

        $this->expectException(\RuntimeException::class);
        $svc->void($period, $user, 'wrong figures');
    }

    public function test_void_requires_a_reason(): void
    {
        $user = $this->makeUser();
        $period = $this->finalizedPeriod($user, postGl: false);

        $this->expectException(\RuntimeException::class);
        app(PayrollPeriodService::class)->void($period, $user, '   ');
    }

    public function test_void_sets_status_and_metadata_and_fires_event(): void
    {
        Event::fake([PayrollPeriodVoided::class]);
        $user = $this->makeUser();
        $period = $this->finalizedPeriod($user, postGl: false);

        $voided = app(PayrollPeriodService::class)->void($period, $user, 'duplicate run');

        $this->assertSame(PayrollPeriodStatus::Voided, $voided->status);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame((int) $user->id, (int) $voided->voided_by);
        $this->assertSame('duplicate run', $voided->void_reason);
        Event::assertDispatched(PayrollPeriodVoided::class);
    }

    public function test_void_reverses_posted_journal_entry(): void
    {
        $user = $this->makeUser();
        $period = $this->finalizedPeriod($user, postGl: true);

        $jeId = (int) $period->journal_entry_id;
        $this->assertGreaterThan(0, $jeId);

        app(PayrollPeriodService::class)->void($period, $user, 'recompute needed');

        // Original JE flagged reversed; a balanced mirror entry now exists.
        $orig = DB::table('journal_entries')->where('id', $jeId)->first();
        $this->assertSame('reversed', $orig->status);
        $this->assertNotNull($orig->reversed_by_entry_id);

        $reversal = DB::table('journal_entries')->where('id', $orig->reversed_by_entry_id)->first();
        $this->assertSame('journal_entry_reversal', $reversal->reference_type);
        $this->assertSame((string) $orig->total_debit, (string) $reversal->total_credit);
        $this->assertSame((string) $orig->total_credit, (string) $reversal->total_debit);
    }

    public function test_voided_period_audit_logged(): void
    {
        $user = $this->makeUser();
        $period = $this->finalizedPeriod($user, postGl: false);

        app(PayrollPeriodService::class)->void($period, $user, 'wrong rates');

        $this->assertDatabaseHas('audit_logs', [
            'action'   => 'payroll.period.void',
            'model_id' => $period->id,
        ]);
    }
}
