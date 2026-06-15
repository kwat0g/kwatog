<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Events\CopqSnapshotComputed;
use App\Modules\Quality\Listeners\AlertOnCopqSpike;
use App\Modules\Quality\Models\CopqSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T3.6.C — AlertOnCopqSpike listener.
 *
 * Listener is invoked synchronously here to avoid queue-driver test gymnastics.
 * Three cases:
 *  1. ≥ +25% MoM jump → notifies qc_inspector + production_manager
 *  2. small jump (< +25%) → silent
 *  3. no prior snapshot → silent
 */
class CopqSpikeAlertTest extends TestCase
{
    use RefreshDatabase;

    private function makeRecipient(string $roleSlug): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst(str_replace('_', ' ', $roleSlug))]);
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function makeSnapshot(int $year, int $month, float $total): CopqSnapshot
    {
        return CopqSnapshot::create([
            'period_year'  => $year,
            'period_month' => $month,
            'total_cost'   => $total,
            'breakdown'    => ['total' => $total],
            'computed_at'  => now(),
        ]);
    }

    private function dispatch(CopqSnapshot $snap): void
    {
        // Run listener synchronously — bypasses queue config + Event::fake gymnastics.
        (new AlertOnCopqSpike(app(NotificationService::class)))
            ->handle(new CopqSnapshotComputed($snap));
    }

    public function test_thirty_percent_mom_jump_dispatches_spike_notification(): void
    {
        $qc   = $this->makeRecipient('qc_inspector');
        $prod = $this->makeRecipient('production_manager');

        // Prior month: ₱1,000 baseline; new month: ₱1,300 (+30%).
        $this->makeSnapshot(2026, 4, 1_000.00);
        $newSnap = $this->makeSnapshot(2026, 5, 1_300.00);

        $this->dispatch($newSnap);

        $count = DB::table('notifications')
            ->whereIn('notifiable_id', [$qc->id, $prod->id])
            ->where('type', 'copq.spike')
            ->count();

        $this->assertSame(2, $count, 'Both qc_inspector and production_manager should be notified.');
    }

    public function test_five_percent_mom_jump_stays_silent(): void
    {
        $this->makeRecipient('qc_inspector');
        $this->makeRecipient('production_manager');

        // Prior ₱1,000 → new ₱1,050 (+5%); below the +25% threshold.
        $this->makeSnapshot(2026, 4, 1_000.00);
        $newSnap = $this->makeSnapshot(2026, 5, 1_050.00);

        $this->dispatch($newSnap);

        $this->assertSame(
            0,
            DB::table('notifications')->where('type', 'copq.spike')->count(),
        );
    }

    public function test_no_prior_snapshot_stays_silent(): void
    {
        $this->makeRecipient('qc_inspector');
        $this->makeRecipient('production_manager');

        // Only the current-month snapshot exists; no baseline → nothing fires.
        $newSnap = $this->makeSnapshot(2026, 5, 5_000.00);

        $this->dispatch($newSnap);

        $this->assertSame(
            0,
            DB::table('notifications')->where('type', 'copq.spike')->count(),
        );
    }
}
