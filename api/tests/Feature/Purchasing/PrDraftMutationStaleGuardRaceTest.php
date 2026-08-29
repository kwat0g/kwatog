<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Draft-mutation guard races on the PR lifecycle — the same P01-01 shape
 * `PrCancelStaleGuardRaceTest` pins for `cancel()`.
 *
 * `submit()`, `approve()`, `reject()` and `cancel()` all lock the authoritative
 * row and re-check status inside their transaction. `update()` and `delete()`
 * did not: both guarded `status` on the instance the caller happened to be
 * holding. A requester who opened the draft, then had `submit()` commit
 * underneath them, could therefore
 *
 *  - soft-delete a request that is now `pending` — leaving current approval
 *    records pointing at a deleted row that no list query returns, so the
 *    approvers keep a badge count for a PR nobody can open; or
 *  - replace every line item after `submit()` had already computed
 *    `totalEstimatedAmount()` into the budget assessment and the approval
 *    threshold — so the amount the chain is approving no longer matches the
 *    lines it will be converted from.
 *
 * Neither needs two processes to reproduce: one stale in-memory instance is
 * enough, which is exactly what these tests hold.
 */
class PrDraftMutationStaleGuardRaceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseRequestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(PurchaseRequestService::class);
    }

    private function draftPr(): PurchaseRequest
    {
        $user = User::factory()->create(['is_active' => true]);

        $pr = PurchaseRequest::create([
            'pr_number' => 'PR-'.substr(uniqid(), -6),
            'requested_by' => $user->id,
            'date' => now()->toDateString(),
            'reason' => 'Draft-mutation race test',
            'priority' => 'normal',
        ]);
        // status is non-fillable; service-only.
        $pr->forceFill(['status' => PurchaseRequestStatus::Draft->value])->save();

        PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'description' => 'Original line',
            'quantity' => '2',
            'unit' => 'pc',
            'estimated_unit_price' => '100.00',
        ]);

        return $pr;
    }

    public function test_delete_after_concurrent_submit_is_blocked(): void
    {
        $pr = $this->draftPr();
        $this->assertSame(PurchaseRequestStatus::Draft, $pr->status);

        // Both actors read the row while it was still a draft.
        $submitterView = PurchaseRequest::query()->findOrFail($pr->id);
        $deleterView = PurchaseRequest::query()->findOrFail($pr->id);

        // The submitter commits first (submit advanced the status).
        $submitterView->forceFill(['status' => PurchaseRequestStatus::Pending->value])->save();

        // The deleter acts on its stale draft instance — must be refused.
        try {
            $this->svc->delete($deleterView);
            $this->fail('delete() accepted a stale draft instance after submit committed.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Only draft PRs can be deleted.', $e->getMessage());
        }

        $this->assertNull(PurchaseRequest::query()->findOrFail($pr->id)->deleted_at);
    }

    public function test_update_after_concurrent_submit_is_blocked(): void
    {
        $pr = $this->draftPr();
        $this->assertSame(PurchaseRequestStatus::Draft, $pr->status);

        $submitterView = PurchaseRequest::query()->findOrFail($pr->id);
        $editorView = PurchaseRequest::query()->findOrFail($pr->id);

        $submitterView->forceFill(['status' => PurchaseRequestStatus::Pending->value])->save();

        try {
            $this->svc->update($editorView, [
                'reason' => 'Rewritten after submit',
                'items' => [
                    ['description' => 'Swapped line', 'quantity' => '99', 'estimated_unit_price' => '9999.00'],
                ],
            ]);
            $this->fail('update() accepted a stale draft instance after submit committed.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Only draft PRs can be edited.', $e->getMessage());
        }

        $fresh = PurchaseRequest::query()->with('items')->findOrFail($pr->id);
        $this->assertSame('Draft-mutation race test', $fresh->reason);
        $this->assertCount(1, $fresh->items);
        $this->assertSame('Original line', $fresh->items->first()->description);
        $this->assertSame('200.00', $fresh->totalEstimatedAmount());
    }
}
