<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR cancel guard race — the P01-01 shape on the P2P approval boundary.
 *
 * `cancel()` guards `status` on the passed model outside any transaction. A
 * cancellation request that read the PR while it was `pending` can therefore
 * cancel an PR that an approver concurrently advanced to `approved` — an
 * approved purchase request silently cancelled. The authoritative row must be
 * locked and re-read before the guard fires.
 */
class PrCancelStaleGuardRaceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseRequestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(PurchaseRequestService::class);
    }

    private function pendingPr(): PurchaseRequest
    {
        $user = User::factory()->create(['is_active' => true]);

        $pr = PurchaseRequest::create([
            'pr_number'    => 'PR-' . substr(uniqid(), -6),
            'requested_by' => $user->id,
            'date'         => now()->toDateString(),
            'reason'       => 'Stale-guard race test',
            'priority'     => 'normal',
        ]);
        // status is non-fillable; service-only.
        $pr->forceFill(['status' => PurchaseRequestStatus::Pending->value])->save();

        return $pr;
    }

    public function test_cancel_after_concurrent_approval_is_blocked(): void
    {
        $pr = $this->pendingPr();
        $this->assertSame(PurchaseRequestStatus::Pending, $pr->status);

        // Approver and canceller both read the row while it was pending.
        $approverView = PurchaseRequest::query()->findOrFail($pr->id);
        $cancellerView = PurchaseRequest::query()->findOrFail($pr->id);

        // Approver commits first (approve path advanced the status).
        $approverView->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        // Canceller acts on its stale pending instance — must be blocked.
        $this->expectException(BusinessRuleException::class);

        $this->svc->cancel($cancellerView);
    }
}
