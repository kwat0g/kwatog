<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Common\Services\SystemActorService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Series C — Task C2. When a PR's final approval lands, convert it straight
 * into purchase order(s) without a human re-typing the lines.
 *
 * Rules (2026-08-08):
 * - Every PR line must already carry a `suggested_vendor_id` (pre-filled from
 *   the preferred approved supplier on submit) AND a unit price. If ANY line
 *   is missing either, the whole PR is skipped — it stays `approved` for the
 *   manual convert-to-PO flow rather than being partially converted.
 * - Lines are grouped by vendor, so a PR spanning two suppliers yields two POs
 *   (reuses PurchaseOrderService::convertFromPr).
 * - The resulting POs are created in `draft` — the normal PO approval chain
 *   (submit → VP threshold → PPAP gate → budget enforcement) still applies.
 * - PR flips to `converted` exactly like the manual conversion, so a second
 *   dispatch of this event can never double-create POs.
 * - Attributed to the PR requester (or the configured automation actor when
 *   the requester is gone); skipped with a log when no actor exists.
 */
class ConsolidatePurchaseOrders implements ShouldQueue
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly SystemActorService $actors,
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function handle(PurchaseRequestApproved $event): void
    {
        $pr = $event->purchaseRequest;

        // Idempotency guard: the event fires once on the approval transition,
        // but a stale queued copy must never re-convert (PR is `converted` by
        // then). Also refuses anything that isn't a freshly-approved PR.
        if ($pr->status !== PurchaseRequestStatus::Approved) {
            return;
        }
        $pr->loadMissing(['items', 'requester']);
        // Only a LIVE PO counts as already-converted. A cancelled PO is a
        // failed attempt — if the PR was re-opened to approved, a fresh
        // conversion must be allowed (mirrors PurchaseOrderService's
        // reopenSourcePrIfLastLink logic).
        $hasLivePo = $pr->purchaseOrders()
            ->where('status', '!=', 'cancelled')
            ->withoutTrashed()
            ->exists();
        if ($hasLivePo) {
            Log::info('ConsolidatePurchaseOrders: PR already has a live PO, skipping', ['pr_id' => $pr->id]);
            return;
        }

        // Every line must name a vendor and carry a price. A partial conversion
        // would strand the remaining lines — skip the whole PR instead.
        $vendorMap = [];
        foreach ($pr->items as $line) {
            if (! $line->suggested_vendor_id) {
                Log::info('ConsolidatePurchaseOrders: line has no suggested vendor, skipping whole PR', [
                    'pr_id' => $pr->id,
                    'pr_item_id' => $line->id,
                ]);
                $this->notifySkipped($pr, 'some line items have no preferred supplier — assign one and convert manually.');
                return;
            }
            if ($line->estimated_unit_price === null || (float) $line->estimated_unit_price <= 0) {
                Log::info('ConsolidatePurchaseOrders: line has no unit price, skipping whole PR', [
                    'pr_id' => $pr->id,
                    'pr_item_id' => $line->id,
                ]);
                $this->notifySkipped($pr, 'some line items have no unit price — set the price and convert manually.');
                return;
            }
            $vendorMap[$line->id] = (int) $line->suggested_vendor_id;
        }
        if ($vendorMap === []) {
            return;
        }

        $by = $pr->requester ?? $this->actors->resolve();
        if (! $by) {
            Log::warning('ConsolidatePurchaseOrders: no actor to attribute the auto-PO to, skipping', [
                'pr_id' => $pr->id,
            ]);
            return;
        }

        try {
            $created = $this->purchaseOrders->convertFromPr($pr, $vendorMap, $by);
            // Distinguish listener-created POs so the UI can label them
            // (PR detail: "PO … auto-created from this PR").
            $ids = array_map(static fn ($po) => $po->id, $created);
            if ($ids !== []) {
                PurchaseOrder::query()->whereIn('id', $ids)->update(['is_auto_generated' => true]);
            }
            Log::info('ConsolidatePurchaseOrders: auto-created POs from approved PR', [
                'pr_id' => $pr->id,
                'pr_number' => $pr->pr_number,
            ]);
        } catch (\Throwable $e) {
            // Never kill the queue job over a conversion failure — the PR stays
            // approved and the manual convert-to-PO flow remains available.
            Log::warning('ConsolidatePurchaseOrders failed — manual conversion still available', [
                'pr_id' => $pr->id,
                'error' => $e->getMessage(),
            ]);
            $this->notifySkipped($pr, 'auto-conversion failed — convert manually.');
        }
    }

    /**
     * Tell the purchasing audience a PR could not be auto-converted, so they
     * fix the missing master data and convert it by hand. Same audience as the
     * "PR approved" notification (purchasing.purchase_request_approved.notification_roles).
     */
    private function notifySkipped(PurchaseRequest $pr, string $reason): void
    {
        try {
            $roles = array_values(array_filter(
                (array) $this->settings->get('purchasing.purchase_request_approved.notification_roles', []),
                static fn ($role): bool => is_string($role) && $role !== '',
            ));
            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();
            $this->notifications->send($audience, 'chain.pr_auto_convert_skipped', [
                'title'       => "PR {$pr->pr_number} needs manual conversion",
                'message'     => "Auto-PO was skipped: {$reason}",
                'link_to'     => "/purchasing/purchase-requests/{$pr->hash_id}",
                'entity_type' => 'purchase_request',
                'entity_id'   => $pr->hash_id,
                'pr_number'   => $pr->pr_number,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ConsolidatePurchaseOrders::notifySkipped failed', ['error' => $e->getMessage()]);
        }
    }
}
