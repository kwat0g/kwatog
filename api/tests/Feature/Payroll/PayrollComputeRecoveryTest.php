<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Events\PayrollProgressEvent;
use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollProgressTracker;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Crash-recovery and run-visibility regressions.
 *
 * Reported symptom: clicking Compute produced only a toast saying the period was
 * already running/queued, with no indication anything was happening, and the
 * status stayed Draft even after amounts had been computed.
 *
 * Investigating that surfaced defects the original report did not name:
 *
 *   1. A Processing claim whose worker died was never released, so every later
 *      Compute click returned "already being computed" forever. Escape required
 *      the payroll.periods.force_unlock permission.
 *   2. ShouldBeUnique's leftover lock made dispatch() a silent no-op, so a
 *      re-run enqueued nothing at all and the period sat at Processing.
 *   3. The per-employee Recompute button could rewrite rows in an APPROVED
 *      period (maker-checker bypass) or race the batch job mid-run.
 *   4. PayrollProgressEvent broadcast on a public channel while the SPA
 *      subscribes privately, so no progress ever reached the browser.
 */
class PayrollComputeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
        ]);
    }

    private function period(string $status = 'draft', ?string $processingStartedAt = null): PayrollPeriod
    {
        $p = PayrollPeriod::factory()->create();
        $p->forceFill([
            'status'                => $status,
            'processing_started_at' => $processingStartedAt,
        ])->save();

        return $p->fresh();
    }

    private function employee(): Employee
    {
        return Employee::factory()->create([
            'department_id' => Department::factory()->create()->id,
            'position_id'   => Position::factory()->create()->id,
        ]);
    }

    /* ── 1. Stale-claim recovery ─────────────────────────────────── */

    public function test_a_live_claim_is_still_refused(): void
    {
        Queue::fake();
        $hr     = $this->userWithRole('hr_officer');
        $period = $this->period(PayrollPeriodStatus::Processing->value, now()->subMinutes(2)->toDateTimeString());

        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This period is already being computed. Wait for the current run to finish.');

        $this->assertSame(0, DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->count());
    }

    /**
     * The defect the user hit: a dead claim wedged the period permanently.
     * Processing is deliberately absent from isComputable(), so the takeover has
     * to be agreed by BOTH guards in claimForCompute — an earlier build passed
     * the staleness check and was then refused by the very next line.
     */
    public function test_a_stale_claim_is_taken_over_by_the_next_compute_click(): void
    {
        Queue::fake();
        $hr     = $this->userWithRole('hr_officer');
        $period = $this->period(PayrollPeriodStatus::Processing->value, now()->subHours(3)->toDateTimeString());

        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
            ->assertStatus(202)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Processing->value);

        // Re-stamped to now and re-attributed to whoever retried.
        $fresh = $period->fresh();
        $this->assertSame($hr->id, $fresh->computed_by);
        $this->assertTrue($fresh->processing_started_at->greaterThan(now()->subMinutes(5)));
        $this->assertSame(1, DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->count());
    }

    /** A Processing row with no stamp at all predates claim tracking. */
    public function test_a_processing_claim_with_no_timestamp_counts_as_stale(): void
    {
        $period = $this->period(PayrollPeriodStatus::Processing->value, null);

        $this->assertTrue(app(PayrollPeriodService::class)->claimIsStale($period));
    }

    /**
     * The threshold must never dip below the job's own timeout, or a healthy
     * long run would be reclaimed out from under its worker mid-batch.
     */
    public function test_stale_threshold_is_floored_above_the_job_timeout(): void
    {
        $service  = app(PayrollPeriodService::class);
        $jobFloor = (int) ceil(ProcessPayrollJob::TIMEOUT_SECONDS / 60);

        $this->assertGreaterThanOrEqual($jobFloor, $service->staleAfterMinutes());

        // Even a nonsensical stored value degrades safely rather than
        // endangering a live run.
        Cache::flush();
        \Illuminate\Support\Facades\DB::table('settings')
            ->updateOrInsert(
                ['key' => 'payroll.compute.stale_after_minutes'],
                ['value' => json_encode(1), 'group' => 'payroll', 'updated_at' => now(), 'created_at' => now()],
            );
        Cache::flush();

        $this->assertGreaterThanOrEqual($jobFloor, $service->staleAfterMinutes());
    }

    public function test_the_reaper_releases_a_stale_claim_onto_computed_when_rows_exist(): void
    {
        $period = $this->period(PayrollPeriodStatus::Processing->value, now()->subHours(3)->toDateTimeString());
        Payroll::factory()->create(['payroll_period_id' => $period->id, 'employee_id' => $this->employee()->id]);

        $this->artisan('payroll:reap-stale-runs')->assertExitCode(0);

        $fresh = $period->fresh();
        $this->assertSame(PayrollPeriodStatus::Computed, $fresh->status);
        $this->assertNull($fresh->processing_started_at);
    }

    /** No rows means the dead run produced nothing — Draft is correct there. */
    public function test_the_reaper_releases_an_empty_stale_claim_onto_draft(): void
    {
        $period = $this->period(PayrollPeriodStatus::Processing->value, now()->subHours(3)->toDateTimeString());

        $this->artisan('payroll:reap-stale-runs')->assertExitCode(0);

        $this->assertSame(PayrollPeriodStatus::Draft, $period->fresh()->status);
    }

    public function test_the_reaper_leaves_a_live_run_alone(): void
    {
        $period = $this->period(PayrollPeriodStatus::Processing->value, now()->subMinutes(2)->toDateTimeString());

        $this->artisan('payroll:reap-stale-runs')->assertExitCode(0);

        $this->assertSame(PayrollPeriodStatus::Processing, $period->fresh()->status);
    }

    public function test_reaper_does_not_release_a_fresh_takeover_from_an_old_stale_snapshot(): void
    {
        $period = $this->period(PayrollPeriodStatus::Processing->value, now()->subHours(3)->toDateTimeString());
        $staleSnapshot = $period->fresh();
        $periods = app(PayrollPeriodService::class);

        $currentOwner = $periods->claimForCompute($period->fresh());
        $released = $periods->reapStaleClaim($staleSnapshot, now()->subMinutes(45));

        $this->assertNull($released);
        $this->assertSame(PayrollPeriodStatus::Processing, $currentOwner->fresh()->status);
        $this->assertSame($currentOwner->processing_token, $currentOwner->fresh()->processing_token);
        $this->assertTrue($currentOwner->fresh()->processing_started_at->greaterThan(now()->subMinutes(5)));
    }

    /* ── 2. Durable compute request ──────────────────────────────── */

    /**
     * The compute claim and its outbox request are committed together. A queue
     * outage can delay publication, but it cannot leave a Processing period
     * without a durable request that the scheduler can replay.
     */
    public function test_compute_request_is_durable_when_the_worker_queue_is_not_consumed(): void
    {
        Queue::fake();
        $hr     = $this->userWithRole('hr_officer');
        $period = $this->period();

        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
            ->assertStatus(202);

        $this->assertSame(1, DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->count());
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => PayrollComputationRequested::class,
        ]);
    }

    /* ── 3. Maker-checker bypass on single-employee recompute ────── */

    public function test_single_employee_recompute_is_refused_on_an_approved_period(): void
    {
        $period = $this->period(PayrollPeriodStatus::Approved->value);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already approved');

        app(PayrollCalculatorService::class)->computeForEmployee($period, $this->employee());
    }

    public function test_single_employee_recompute_is_refused_while_a_batch_run_is_in_flight(): void
    {
        $period = $this->period(PayrollPeriodStatus::Processing->value, now()->toDateTimeString());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('compute run is currently in progress');

        app(PayrollCalculatorService::class)->computeForEmployee($period, $this->employee());
    }

    /**
     * The batch job legitimately runs against a Processing period — its own
     * claim is what put it there — so it opts out via internal: true.
     */
    public function test_the_batch_job_may_compute_a_processing_period(): void
    {
        $period = $this->period(PayrollPeriodStatus::Processing->value, now()->toDateTimeString());

        $payroll = app(PayrollCalculatorService::class)
            ->computeForEmployee($period, $this->employee(), internal: true);

        $this->assertSame($period->id, $payroll->payroll_period_id);
    }

    /** Draft and Computed remain recomputable for external callers. */
    public function test_single_employee_recompute_still_works_on_a_computed_period(): void
    {
        $period = $this->period(PayrollPeriodStatus::Computed->value);

        $payroll = app(PayrollCalculatorService::class)->computeForEmployee($period, $this->employee());

        $this->assertSame($period->id, $payroll->payroll_period_id);
    }

    /* ── 4. Progress visibility ──────────────────────────────────── */

    /**
     * useEcho subscribes with echo.private(), and routes/channels.php gates
     * payroll.period.{hashId} on payroll.periods.view. A public Channel here
     * meant that authorisation was never consulted AND the SPA received nothing.
     */
    public function test_progress_broadcasts_privately_under_a_stable_event_name(): void
    {
        $period = $this->period();
        $event  = new PayrollProgressEvent($period, 40, 200, 1);

        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn());
        $this->assertSame("private-payroll.period.{$period->hash_id}", $event->broadcastOn()->name);
        $this->assertSame('payroll.progress', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame(20, $payload['percent']);
        $this->assertSame(1, $payload['failures']);
        // Carries status so the page can leave the processing state on the
        // final emit instead of waiting for the next poll.
        $this->assertArrayHasKey('status', $payload);
    }

    public function test_progress_snapshot_survives_a_page_reload_mid_run(): void
    {
        $period  = $this->period(PayrollPeriodStatus::Processing->value, now()->toDateTimeString());
        $tracker = app(PayrollProgressTracker::class);

        $tracker->put($period, 142, 200, 0);

        $this->assertSame(
            ['processed' => 142, 'total' => 200, 'failures' => 0, 'percent' => 71],
            collect($tracker->get($period))->except('updated_at')->all(),
        );
    }

    /** A new run must never open showing the previous run's numbers. */
    public function test_claiming_a_period_clears_the_previous_runs_snapshot(): void
    {
        Queue::fake();
        $hr      = $this->userWithRole('hr_officer');
        $period  = $this->period(PayrollPeriodStatus::Computed->value);
        $tracker = app(PayrollProgressTracker::class);

        $tracker->put($period, 200, 200, 0);
        app(PayrollPeriodService::class)->claimForCompute($period->fresh(), $hr);

        $this->assertNull($tracker->get($period->fresh()));
    }

    public function test_the_period_endpoint_exposes_run_telemetry_for_the_progress_bar(): void
    {
        $hr      = $this->userWithRole('hr_officer');
        $period  = $this->period(PayrollPeriodStatus::Processing->value, now()->subHours(3)->toDateTimeString());
        app(PayrollProgressTracker::class)->put($period, 90, 200, 2);

        $this->actingAs($hr)
            ->getJson("/api/v1/payroll-periods/{$period->hash_id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_compute_stale', true)
            ->assertJsonPath('data.compute_progress.processed', 90)
            ->assertJsonPath('data.compute_progress.percent', 45)
            ->assertJsonPath('data.compute_progress.failures', 2);
    }

    /** Telemetry is short-circuited off status so list views stay cheap. */
    public function test_a_period_that_is_not_running_reports_no_progress(): void
    {
        $hr     = $this->userWithRole('hr_officer');
        $period = $this->period(PayrollPeriodStatus::Computed->value);

        $this->actingAs($hr)
            ->getJson("/api/v1/payroll-periods/{$period->hash_id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_compute_stale', false)
            ->assertJsonPath('data.compute_progress', null);
    }
}
