<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Common\Services\SystemActorService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
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
 *   the requester is gone). Missing master data or attribution is recorded as
 *   a durable `manual_required` conversion outcome, not only a log line.
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
        // Queue payloads contain a serialized PR snapshot. Re-read it before
        // mutating the conversion outcome so a delayed approval event cannot
        // overwrite a converted/cancelled request.
        $pr = $event->purchaseRequest->fresh();
        if (! $pr) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'purchase_request_missing');
            return;
        }

        // Idempotency guard: the event fires once on the approval transition,
        // but a stale queued copy must never re-convert (PR is `converted` by
        // then). Also refuses anything that isn't a freshly-approved PR.
        if ($pr->status !== PurchaseRequestStatus::Approved) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'stale_or_not_approved');
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
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'purchase_order_already_exists');
            return;
        }

        // Claim the conversion only while the authoritative PR is still
        // approved. A manual conversion or cancellation that wins this race
        // makes the queued event a no-op.
        if (! $pr->markPoConversionPending()) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'conversion_claim_lost');
            return;
        }
        $pr = $pr->fresh();
        if (! $pr) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'purchase_request_missing_after_claim');
            return;
        }
        $pr->loadMissing(['items', 'requester']);

        // Every line must name a vendor and carry a price. A partial conversion
        // would strand the remaining lines — skip the whole PR instead.
        $vendorMap = [];
        foreach ($pr->items as $line) {
            if (! $line->suggested_vendor_id) {
                Log::info('ConsolidatePurchaseOrders: line has no suggested vendor, skipping whole PR', [
                    'pr_id' => $pr->id,
                    'pr_item_id' => $line->id,
                ]);
                $this->recordManualConversionOutcome($pr, 'Some line items have no preferred supplier — assign one and convert manually.');
                return;
            }
            if ($line->estimated_unit_price === null || (float) $line->estimated_unit_price <= 0) {
                Log::info('ConsolidatePurchaseOrders: line has no unit price, skipping whole PR', [
                    'pr_id' => $pr->id,
                    'pr_item_id' => $line->id,
                ]);
                $this->recordManualConversionOutcome($pr, 'Some line items have no unit price — set the price and convert manually.');
                return;
            }
            $vendorMap[$line->id] = (int) $line->suggested_vendor_id;
        }
        if ($vendorMap === []) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'purchase_request_has_no_lines');
            return;
        }

        $by = $pr->requester ?? $this->actors->resolve();
        if (! $by) {
            $this->recordManualConversionOutcome($pr, 'No active actor is available to attribute the automatic purchase order — convert manually.');
            Log::warning('ConsolidatePurchaseOrders: no actor to attribute the auto-PO to, manual conversion required', [
                'pr_id' => $pr->id,
            ]);
            return;
        }

        try {
            $pos = $this->purchaseOrders->convertFromPr($pr, $vendorMap, $by, true);
            // The service marks only POs created by this path as automatic. If
            // a manual conversion won the race, its existing POs are returned
            // for idempotency but retain their original attribution.
            Log::info('ConsolidatePurchaseOrders: processed approved PR', [
                'pr_id' => $pr->id,
                'pr_number' => $pr->pr_number,
                'po_count' => count($pos),
            ]);
            app(ChainListenerRunService::class)->recordOutcome(
                count($pos) > 0 ? 'completed' : 'skipped',
                count($pos) > 0 ? 'purchase_orders_created' : 'purchase_orders_already_present_or_not_created',
                count($pos) > 0 ? 'Approved purchase request converted to purchase order(s).' : null,
            );
        } catch (BusinessRuleException $e) {
            // A known data/state rule is an intentional manual handoff. Keep
            // the PR approved and notify purchasing without poisoning retries.
            Log::warning('ConsolidatePurchaseOrders failed — manual conversion still available', [
                'pr_id' => $pr->id,
                'error' => $e->getMessage(),
            ]);
            $this->recordManualConversionOutcome($pr, 'Auto-conversion failed — convert manually.');
        } catch (\Throwable $e) {
            // Unexpected database, infrastructure, or integration failures
            // must reach the queue worker so Redis retries and failed_jobs can
            // surface the broken chain.
            Log::error('ConsolidatePurchaseOrders failed unexpectedly', [
                'pr_id' => $pr->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Tell the purchasing audience a PR could not be auto-converted, so they
     * fix the missing master data and convert it by hand. Same audience as the
     * "PR approved" notification (purchasing.purchase_request_approved.notification_roles).
     */
    private function recordManualConversionOutcome(PurchaseRequest $pr, string $reason): void
    {
        if ($pr->markPoConversionManualRequired($reason)) {
            $this->notifySkipped($pr, $reason);
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'purchase_request_manual_conversion_required',
                $reason,
            );
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'skipped',
            'manual_conversion_already_recorded_or_request_changed',
        );
    }

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
