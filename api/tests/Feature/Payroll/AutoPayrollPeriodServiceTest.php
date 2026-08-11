<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\AutoPayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoPayrollPeriodServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    public function test_auto_creation_claims_and_stages_a_durable_compute_request(): void
    {
        Queue::fake();
        $now = Carbon::parse('2026-08-14 23:00:00');

        $period = app(AutoPayrollPeriodService::class)
            ->createForSecondHalfOfCurrentMonth($now);

        $this->assertNotNull($period);
        $this->assertTrue((bool) $period->is_auto_created);
        $this->assertSame('2026-08-16:regular', $period->auto_idempotency_key);
        $this->assertSame(PayrollPeriodStatus::Processing, $period->fresh()->status);
        $this->assertSame(1, DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->count());
        $this->assertDatabaseHas('chain_step_runs', [
            'chain' => 'h2r',
            'entity_type' => 'payroll_period',
            'entity_id' => $period->id,
            'step' => 'compute',
        ]);

        // The normal scheduler retry sees the existing cutoff and does not
        // create or stage a second run.
        $this->assertNull(
            app(AutoPayrollPeriodService::class)->createForSecondHalfOfCurrentMonth($now),
        );
        $this->assertSame(1, PayrollPeriod::query()
            ->where('auto_idempotency_key', '2026-08-16:regular')
            ->count());
    }

    public function test_auto_creation_stands_down_when_a_human_period_overlaps_the_cutoff(): void
    {
        PayrollPeriod::factory()->create([
            'period_start' => '2026-08-16',
            'period_end' => '2026-08-31',
            'is_thirteenth_month' => false,
            'status' => PayrollPeriodStatus::Draft->value,
        ]);

        $period = app(AutoPayrollPeriodService::class)
            ->createForSecondHalfOfCurrentMonth(Carbon::parse('2026-08-14 23:00:00'));

        $this->assertNull($period);
        $this->assertSame(0, DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->count());
        $this->assertSame(0, PayrollPeriod::query()
            ->where('is_auto_created', true)
            ->count());
    }

    public function test_explicit_target_month_can_recover_a_missed_first_half(): void
    {
        Queue::fake();

        $period = app(AutoPayrollPeriodService::class)
            ->createForFirstHalfOfMonth(2026, 8);

        $this->assertNotNull($period);
        $this->assertSame('2026-08-01', $period->period_start->toDateString());
        $this->assertSame('2026-08-15', $period->period_end->toDateString());
        $this->assertSame(PayrollPeriodStatus::Processing, $period->fresh()->status);
    }

    public function test_explicit_target_month_is_idempotent_for_second_half(): void
    {
        Queue::fake();

        $first = app(AutoPayrollPeriodService::class)
            ->createForSecondHalfOfMonth(2026, 8);
        $second = app(AutoPayrollPeriodService::class)
            ->createForSecondHalfOfMonth(2026, 8);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, PayrollPeriod::query()
            ->where('auto_idempotency_key', '2026-08-16:regular')
            ->count());
    }

    public function test_reconciliation_recovers_first_half_inside_the_active_window(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-08-03 23:30:00'));

        try {
            $this->artisan('payroll:reconcile-auto-periods')
                ->assertExitCode(0);

            $this->assertDatabaseHas('payroll_periods', [
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-15',
                'is_auto_created' => true,
            ]);
        } finally {
            $this->travelBack();
        }
    }
}
