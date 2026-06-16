<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Auth\Models\User;
use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * OGAMI-015 — mrp:reap-stale-runs.
 *
 * Marks Running mrp_runs older than the stale threshold as Failed and cancels
 * the orphan draft auto-PRs created during the dead run's window. Fresh Running
 * rows, already-finished rows, and non-orphan PRs are left untouched.
 */
class MrpReaperTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function makeRun(MrpRunStatus $status, Carbon $startedAt): MrpRun
    {
        $run = MrpRun::create([
            'run_at'       => $startedAt,
            'started_at'   => $startedAt,
            'heartbeat_at' => $startedAt,
            'triggered_by' => MrpRunTrigger::Scheduled->value,
            'status'       => $status->value,
        ]);
        // run_at/started_at are real columns; ensure they persisted as given.
        $run->forceFill(['run_at' => $startedAt, 'started_at' => $startedAt])->saveQuietly();
        return $run->fresh();
    }

    private function autoPr(string $status, Carbon $createdAt): PurchaseRequest
    {
        $pr = PurchaseRequest::create([
            'pr_number'         => 'PR-' . substr(uniqid(), -8),
            'requested_by'      => $this->user->id,
            'date'              => Carbon::today(),
            'reason'            => 'auto',
            'priority'          => 'normal',
            'is_auto_generated' => true,
        ]);
        $pr->forceFill(['status' => $status, 'created_at' => $createdAt])->saveQuietly();
        return $pr->fresh();
    }

    // ────────────────────────────────────────────────────────────────────────

    public function test_stale_running_run_is_marked_failed(): void
    {
        $stale = $this->makeRun(MrpRunStatus::Running, Carbon::now()->subHours(3));

        $this->artisan('mrp:reap-stale-runs')->assertSuccessful();

        $this->assertSame(MrpRunStatus::Failed, $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->error_message);
    }

    public function test_fresh_running_run_is_left_alone(): void
    {
        $fresh = $this->makeRun(MrpRunStatus::Running, Carbon::now()->subMinutes(10));

        $this->artisan('mrp:reap-stale-runs')->assertSuccessful();

        $this->assertSame(MrpRunStatus::Running, $fresh->fresh()->status);
    }

    public function test_completed_run_is_not_touched(): void
    {
        $done = $this->makeRun(MrpRunStatus::Completed, Carbon::now()->subDays(2));

        $this->artisan('mrp:reap-stale-runs')->assertSuccessful();

        $this->assertSame(MrpRunStatus::Completed, $done->fresh()->status);
    }

    public function test_orphan_draft_auto_pr_in_run_window_is_cancelled(): void
    {
        $this->makeRun(MrpRunStatus::Running, Carbon::now()->subHours(3));
        // Draft auto-PR created after the run started → orphan.
        $orphan = $this->autoPr(PurchaseRequestStatus::Draft->value, Carbon::now()->subHours(2));

        $this->artisan('mrp:reap-stale-runs')->assertSuccessful();

        $this->assertSame(PurchaseRequestStatus::Cancelled, $orphan->fresh()->status);
    }

    public function test_non_draft_auto_pr_is_not_cancelled(): void
    {
        $this->makeRun(MrpRunStatus::Running, Carbon::now()->subHours(3));
        $approved = $this->autoPr(PurchaseRequestStatus::Approved->value, Carbon::now()->subHours(2));

        $this->artisan('mrp:reap-stale-runs')->assertSuccessful();

        $this->assertSame(PurchaseRequestStatus::Approved, $approved->fresh()->status);
    }

    public function test_draft_auto_pr_created_before_run_window_is_kept(): void
    {
        $this->makeRun(MrpRunStatus::Running, Carbon::now()->subHours(3));
        // Created BEFORE the stale run started → not an orphan of this run.
        $older = $this->autoPr(PurchaseRequestStatus::Draft->value, Carbon::now()->subHours(5));

        $this->artisan('mrp:reap-stale-runs')->assertSuccessful();

        $this->assertSame(PurchaseRequestStatus::Draft, $older->fresh()->status);
    }

    public function test_custom_minutes_threshold_is_respected(): void
    {
        // 90-minute-old run is stale only when threshold drops below 90.
        $run = $this->makeRun(MrpRunStatus::Running, Carbon::now()->subMinutes(90));

        $this->artisan('mrp:reap-stale-runs', ['--minutes' => 30])->assertSuccessful();

        $this->assertSame(MrpRunStatus::Failed, $run->fresh()->status);
    }
}
