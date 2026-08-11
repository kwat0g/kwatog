<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Compute lifecycle + button-spam regression.
 *
 * The reported defect: clicking Compute showed "Computation queued", the period
 * stayed at Draft for the whole run, and the button never disabled — so a user
 * could spam it and each click queued another full recompute of payroll that had
 * already been computed.
 *
 * Root cause was a conflated status: Draft meant BOTH "never computed" and
 * "computed, awaiting approval". These tests pin the new Computed state and the
 * atomic claim that makes a second click impossible.
 */
class PayrollComputeLifecycleTest extends TestCase
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

    private function period(string $status = 'draft'): PayrollPeriod
    {
        $p = PayrollPeriod::factory()->create();
        $p->forceFill(['status' => $status])->save();

        return $p->fresh();
    }

    public function test_compute_returns_processing_status_so_the_button_can_disable_immediately(): void
    {
        Queue::fake();
        $hr = $this->userWithRole('hr_officer');
        $period = $this->period();

        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
            ->assertStatus(202)
            // The old response said 'draft' here, which is what let the SPA keep
            // the button live and skip polling.
            ->assertJsonPath('data.status', PayrollPeriodStatus::Processing->value);

        $this->assertSame(PayrollPeriodStatus::Processing, $period->fresh()->status);
        $outboxId = DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->value('id');
        Queue::assertPushed(DispatchOutboxMessage::class, fn (DispatchOutboxMessage $job): bool => $job->outboxId === $outboxId);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => PayrollComputationRequested::class,
        ]);
        $this->assertDatabaseHas('chain_step_runs', [
            'chain' => 'h2r',
            'entity_type' => 'payroll_period',
            'entity_id' => $period->id,
            'step' => 'compute',
        ]);
    }

    public function test_second_compute_click_is_rejected_while_a_run_is_in_flight(): void
    {
        Queue::fake();
        $hr = $this->userWithRole('hr_officer');
        $period = $this->period();

        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
            ->assertStatus(202);

        // This is the spam click. It must not enqueue a second run.
        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This period is already being computed. Wait for the current run to finish.');

        $this->assertSame(1, DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->count());
    }

    public function test_compute_is_refused_once_the_period_is_approved(): void
    {
        Queue::fake();
        $hr = $this->userWithRole('hr_officer');
        $period = $this->period(PayrollPeriodStatus::Approved->value);

        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
            ->assertStatus(422);

        $this->assertSame(PayrollPeriodStatus::Approved, $period->fresh()->status);
        $this->assertSame(0, DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->count());
    }

    /** Finalized / Disbursed / Voided payroll must never be recomputable. */
    public function test_compute_is_refused_for_every_locked_status(): void
    {
        Queue::fake();
        $hr = $this->userWithRole('hr_officer');

        foreach ([
            PayrollPeriodStatus::Finalized,
            PayrollPeriodStatus::Disbursed,
            PayrollPeriodStatus::Voided,
        ] as $status) {
            $period = $this->period($status->value);

            $this->actingAs($hr)
                ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
                ->assertStatus(422);

            $this->assertSame($status, $period->fresh()->status);
        }

        $this->assertSame(0, DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->count());
    }

    /** A computed period may be recomputed — that is the sanctioned re-run. */
    public function test_computed_period_can_be_recomputed(): void
    {
        Queue::fake();
        $hr = $this->userWithRole('hr_officer');
        $period = $this->period(PayrollPeriodStatus::Computed->value);

        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
            ->assertStatus(202)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Processing->value);

        $this->assertSame(1, DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->count());
    }

    public function test_claim_stamps_the_maker_for_maker_checker(): void
    {
        Queue::fake();
        $hr = $this->userWithRole('hr_officer');
        $period = $this->period();

        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/compute")
            ->assertStatus(202);

        $this->assertSame($hr->id, $period->fresh()->computed_by);
    }

    /**
     * Approving an uncomputed period used to lock in an empty ₱0 payroll that
     * could then be finalized and posted to the GL.
     */
    public function test_draft_period_cannot_be_approved(): void
    {
        $checker = $this->userWithRole('finance_officer');
        $period  = $this->period();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This period has not been computed yet.');
        app(PayrollPeriodService::class)->approve($period, $checker);
    }

    public function test_computed_period_with_no_rows_cannot_be_approved(): void
    {
        $checker = $this->userWithRole('finance_officer');
        $period  = $this->period(PayrollPeriodStatus::Computed->value);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no payroll rows');
        app(PayrollPeriodService::class)->approve($period, $checker);
    }

    public function test_force_unlock_lands_on_draft_and_records_the_actor(): void
    {
        $admin  = $this->userWithRole('system_admin');
        $period = $this->period(PayrollPeriodStatus::Processing->value);

        $this->actingAs($admin)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/force-unlock", [
                'reason' => 'worker OOM-killed',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Draft->value);

        // force_unlocked_by existed as a column but was never written before.
        $this->assertSame($admin->id, $period->fresh()->force_unlocked_by);
    }
}
