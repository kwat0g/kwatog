<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollGlPostingService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\PayrollChartAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase-2 regression tests for the process-hardening audit's PROVEN money
 * findings (docs/PROCESS-HARDENING-AUDIT-2026-08-11.md §3):
 *
 *   P01-01  forceUnlock demotes a Finalized period (data-corrupting)
 *   P01-02  re-finalize after void records no new GL request (silent failure)
 *   P02-01  payroll JE written with no actor and no audit row (bypassable)
 *   P02-02  a voided period keeps journal_entry_id, so every re-post path
 *           silently no-ops (silent failure)
 */
class PayrollMoneyFindingsRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->seed(PayrollChartAccountsSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
        ]);
    }

    /** Build an APPROVED payroll period with one employee + real payroll row. */
    private function approvedPeriod(): array
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
            'created_by' => $user->id,
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

        return [$user, $period];
    }

    // ─── P01-01 ────────────────────────────────────────────────────────────

    /**
     * P01-01 PROVEN — forceUnlock must refuse a Finalized period even when the
     * caller holds a stale model that was loaded while the period was still
     * Processing (the guard must read the locked row, not the route-bound one).
     */
    public function test_p01_01_force_unlock_refuses_finalized_period_from_stale_model(): void
    {
        $admin = $this->userWithRole('system_admin');
        // status is not fillable — the factory row lands on Draft, so stamp
        // Processing explicitly to model a run that is stuck mid-computation.
        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => PayrollPeriodStatus::Processing->value])->save();

        // Simulate a concurrent finalize landing between the operator's page
        // load and their force-unlock click.
        $stale = $period->fresh(); // still Processing
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        try {
            app(PayrollPeriodService::class)->forceUnlock($stale, $admin, 'probe');
            $this->fail('forceUnlock must refuse a period that has since been finalized.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Processing', $e->getMessage());
        }

        $this->assertSame(
            PayrollPeriodStatus::Finalized,
            $period->fresh()->status,
            'A finalized period must never be demoted by forceUnlock.',
        );
    }

    // ─── P01-02 ────────────────────────────────────────────────────────────

    /**
     * P01-02 PROVEN — a second finalize must stage a NEW GL request even when
     * an earlier finalize's outbox row for the same period is already
     * published. The dedupe key needs a run discriminator, not just the period
     * id (which silently swallows the re-request via insertOrIgnore).
     */
    public function test_p01_02_refinalize_stages_a_fresh_gl_request(): void
    {
        $settings = app(\App\Common\Services\SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        [, $period] = $this->approvedPeriod();

        // First finalize stages a GL request; simulate it having been delivered
        // (published) — the exact state left behind by the first run.
        $first = app(PayrollPeriodService::class)->finalize($period->fresh(), $this->userWithRole('finance_officer'));

        $keyPrefix = 'payroll-gl-finalize:'.$first->id;
        DB::table('event_outbox')
            ->where('dedupe_key', 'like', $keyPrefix.'%')
            ->update(['status' => 'published']);

        $before = DB::table('event_outbox')
            ->where('dedupe_key', 'like', $keyPrefix.'%')
            ->count();

        // Simulate the sanctioned correction path: void → force-unlock →
        // recompute → approve, then finalize again.
        $admin = $this->userWithRole('system_admin');
        $finance = $this->userWithRole('finance_officer');
        app(PayrollPeriodService::class)->void($first->fresh(), $admin, 'probe re-finalize');
        $voided = $first->fresh();
        $voided->forceFill(['status' => PayrollPeriodStatus::Computed->value])->save();
        app(PayrollPeriodService::class)->approve($voided->fresh(), $finance);

        app(PayrollPeriodService::class)->finalize($voided->fresh(), $finance);

        $after = DB::table('event_outbox')
            ->where('dedupe_key', 'like', $keyPrefix.'%')
            ->count();

        $this->assertGreaterThan(
            $before,
            $after,
            'Re-finalizing a period must stage a new GL request (dedupe key needs a run discriminator).',
        );
    }

    // ─── P02-01 ────────────────────────────────────────────────────────────

    /**
     * P02-01 PROVEN — the payroll journal entry must carry an actor
     * (created_by/posted_by) and write an audit_logs row, exactly like every
     * other posted entry.
     */
    public function test_p02_01_payroll_je_has_actor_and_audit_row(): void
    {
        $settings = app(\App\Common\Services\SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        [$user, $period] = $this->approvedPeriod();
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value, 'finalized_by' => $user->id])->save();

        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());

        $this->assertNotNull($entryId);
        $entry = DB::table('journal_entries')->where('id', $entryId)->first();

        $this->assertNotNull($entry->created_by, 'Payroll JE must record who created it.');
        $this->assertNotNull($entry->posted_by, 'Payroll JE must record who posted it.');

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => \App\Modules\Accounting\Models\JournalEntry::class,
            'model_id'   => (int) $entryId,
        ]);
    }

    // ─── P02-02 ────────────────────────────────────────────────────────────

    /**
     * P02-02 PROVEN — voiding a GL-posted period must clear journal_entry_id,
     * otherwise every re-post path treats the (now reversed) entry as live and
     * silently no-ops.
     */
    public function test_p02_02_void_clears_journal_entry_id(): void
    {
        $settings = app(\App\Common\Services\SettingsService::class);
        $settings->set('modules.accounting', true, 'modules');

        [$user, $period] = $this->approvedPeriod();
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value, 'finalized_by' => $user->id])->save();

        $entryId = app(PayrollGlPostingService::class)->post($period->fresh());
        $this->assertNotNull($entryId);
        $period->refresh();
        $this->assertSame((int) $entryId, (int) $period->journal_entry_id);

        $admin = $this->userWithRole('system_admin');
        app(PayrollPeriodService::class)->void($period->fresh(), $admin, 'probe void clears link');

        $fresh = $period->fresh();
        $this->assertNull($fresh->journal_entry_id, 'A voided period must not keep pointing at its reversed journal entry.');
    }
}
