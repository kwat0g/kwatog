<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Common\Services\SettingsService;
use App\Modules\Purchasing\Contracts\SupplierDispatchGateway;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\SupplierDispatchStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierOrderDispatch;
use App\Modules\Purchasing\Support\SupplierDispatchResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SupplierDispatchService
{
    public function __construct(
        private readonly SupplierDispatchGateway $gateway,
        private readonly SettingsService $settings,
    ) {}

    public function prepareForApproved(PurchaseOrder $purchaseOrder): ?SupplierOrderDispatch
    {
        $claimed = $this->claim($purchaseOrder);
        if ($claimed === null) {
            return null;
        }

        // `claim()` also reconciles a PO that was already marked sent. That
        // path returns a terminal confirmation row; it must never fall
        // through to the provider boundary on a replayed approval event.
        if ($claimed->status !== SupplierDispatchStatus::Pending) {
            return $claimed;
        }

        try {
            $result = $this->gateway->publish($purchaseOrder->fresh() ?? $purchaseOrder, $claimed->idempotency_key);
        } catch (Throwable $e) {
            $this->recordFailure($claimed->id, $e);
            throw $e;
        }

        return $this->complete($claimed->id, $result, $purchaseOrder);
    }

    /**
     * Confirm the external transmission boundary. This is deliberately
     * separate from portal publication: a PO becomes `sent` only after a
     * user or supplier acknowledgement proves that the document was sent.
     */
    public function confirmSent(PurchaseOrder $purchaseOrder, ?string $channel = null): SupplierOrderDispatch
    {
        $key = $this->idempotencyKey($purchaseOrder);

        return DB::transaction(function () use ($purchaseOrder, $channel, $key): SupplierOrderDispatch {
            $dispatch = SupplierOrderDispatch::query()
                ->where('purchase_order_id', $purchaseOrder->id)
                ->lockForUpdate()
                ->first();
            if ($dispatch?->status === SupplierDispatchStatus::Cancelled) {
                // A cancellation is terminal for this PO's dispatch ledger;
                // a late portal acknowledgement must not resurrect it.
                return $dispatch->fresh();
            }
            $metadata = is_array($dispatch?->metadata) ? $dispatch->metadata : [];
            $metadata['confirmed_by_process'] = true;

            if ($dispatch === null) {
                $dispatch = new SupplierOrderDispatch;
                $dispatch->purchase_order_id = $purchaseOrder->id;
            }

            $dispatch->forceFill([
                'idempotency_key' => $key,
                'channel' => $channel ?? $dispatch->channel ?? 'manual_confirmation',
                'status' => SupplierDispatchStatus::Confirmed,
                'confirmed_at' => now(),
                'last_error' => null,
                'metadata' => $metadata,
            ])->save();

            return $dispatch->fresh();
        });
    }

    /**
     * Close the dispatch ledger when a purchase order is cancelled. This is
     * intentionally idempotent because the cancellation event can be replayed
     * after the synchronous cancellation path has already reconciled the row.
     */
    public function cancelForPurchaseOrder(PurchaseOrder $purchaseOrder, string $reason): ?SupplierOrderDispatch
    {
        return $this->cancelDispatch($purchaseOrder->id, $reason);
    }

    /**
     * Reconcile dispatch rows left behind by a crashed queue worker or a
     * provider timeout. Only stale `pending` rows are automatic candidates;
     * failed rows require the explicit --retry-failed operator decision.
     * Portal/manual/confirmed rows are proof or human-action states and are
     * never retried by this sweep.
     *
     * @return array{scanned:int,recovered:int,confirmed:int,cancelled:int,skipped:int,failed:int}
     */
    public function recoverStale(
        int $limit = 100,
        ?int $staleAfterMinutes = null,
        bool $retryFailed = false,
    ): array {
        $limit = max(1, min(500, $limit));
        $staleAfterMinutes ??= $this->settings->requiredInt(
            'purchasing.supplier_dispatch.stale_after_minutes',
            1,
            1440,
        );
        $threshold = now()->subMinutes(max(1, $staleAfterMinutes));

        $rows = SupplierOrderDispatch::query()
            ->where(function ($query) use ($threshold, $retryFailed): void {
                $query->where(function ($pending) use ($threshold): void {
                    $pending
                        ->where('status', SupplierDispatchStatus::Pending->value)
                        ->where(function ($age) use ($threshold): void {
                            $age
                                ->where('last_attempt_at', '<=', $threshold)
                                ->orWhereNull('last_attempt_at');
                        });
                });

                if ($retryFailed) {
                    $query->orWhere('status', SupplierDispatchStatus::Failed->value);
                }
            })
            ->orderByRaw('CASE WHEN last_attempt_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_attempt_at')
            ->limit($limit)
            ->get();

        $result = [
            'scanned' => $rows->count(),
            'recovered' => 0,
            'confirmed' => 0,
            'cancelled' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $dispatch) {
            try {
                $outcome = $this->recoverOne($dispatch, $retryFailed);
                $result[$outcome]++;
            } catch (Throwable $e) {
                $result['failed']++;
                Log::warning('Supplier dispatch recovery failed', [
                    'dispatch_id' => $dispatch->id,
                    'purchase_order_id' => $dispatch->purchase_order_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function claim(PurchaseOrder $purchaseOrder): ?SupplierOrderDispatch
    {
        return DB::transaction(function () use ($purchaseOrder): ?SupplierOrderDispatch {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);

            if ($po->status === PurchaseOrderStatus::Sent) {
                return $this->confirmSent($po);
            }
            if ($po->status !== PurchaseOrderStatus::Approved) {
                return null;
            }

            $dispatch = SupplierOrderDispatch::query()
                ->where('purchase_order_id', $po->id)
                ->lockForUpdate()
                ->first();

            if ($dispatch && in_array($dispatch->status, [
                SupplierDispatchStatus::PortalAvailable,
                SupplierDispatchStatus::ManualRequired,
                SupplierDispatchStatus::Confirmed,
                SupplierDispatchStatus::Cancelled,
            ], true)) {
                return null;
            }

            $now = now();
            if ($dispatch
                && $dispatch->status === SupplierDispatchStatus::Pending
                && $dispatch->last_attempt_at?->gte($now->copy()->subMinutes(
                    $this->settings->requiredInt('purchasing.supplier_dispatch.stale_after_minutes', 1, 1440),
                ))) {
                // Another worker currently owns this idempotency key. A
                // stale pending row is reclaimable; a recent one is not.
                return null;
            }
            if ($dispatch === null) {
                $dispatch = SupplierOrderDispatch::query()->create([
                    'purchase_order_id' => $po->id,
                    'idempotency_key' => $this->idempotencyKey($po),
                    'status' => SupplierDispatchStatus::Pending,
                    'attempts' => 1,
                    'queued_at' => $now,
                    'last_attempt_at' => $now,
                ]);
            } else {
                $dispatch->forceFill([
                    'status' => SupplierDispatchStatus::Pending,
                    'attempts' => (int) $dispatch->attempts + 1,
                    'queued_at' => $dispatch->queued_at ?? $now,
                    'last_attempt_at' => $now,
                    'last_error' => null,
                ])->save();
            }

            return $dispatch->fresh();
        });
    }

    private function complete(int $dispatchId, SupplierDispatchResult $result, PurchaseOrder $purchaseOrder): SupplierOrderDispatch
    {
        $dispatch = DB::transaction(function () use ($dispatchId, $result): SupplierOrderDispatch {
            $dispatch = SupplierOrderDispatch::query()->lockForUpdate()->findOrFail($dispatchId);
            if ($dispatch->status !== SupplierDispatchStatus::Pending) {
                return $dispatch;
            }
            $now = now();
            $metadata = is_array($result->metadata) ? $result->metadata : [];
            $metadata['idempotency_key'] = $dispatch->idempotency_key;
            $dispatch->forceFill([
                'channel' => $result->channel,
                'status' => $result->status,
                'recipient_count' => $result->recipientCount,
                'published_at' => $result->status === SupplierDispatchStatus::PortalAvailable ? $now : null,
                'last_error' => $result->note,
                'metadata' => $metadata,
            ])->save();

            return $dispatch->fresh();
        });

        if (in_array($dispatch->status, [
            SupplierDispatchStatus::PortalAvailable,
            SupplierDispatchStatus::ManualRequired,
        ], true)) {
            try {
                $this->notifyPurchasing($purchaseOrder, $dispatch->status);
            } catch (\Throwable $e) {
                Log::warning('Supplier dispatch action alert failed; dispatch state remains durable.', [
                    'purchase_order_id' => $purchaseOrder->id,
                    'dispatch_id' => $dispatch->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dispatch;
    }

    private function notifyPurchasing(PurchaseOrder $purchaseOrder, SupplierDispatchStatus $status): void
    {
        $recipients = User::query()
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'purchasing.view'))
            ->where('is_active', true)
            ->get();

        $isPortal = $status === SupplierDispatchStatus::PortalAvailable;
        app(NotificationService::class)->sendInApp($recipients, 'supplier.dispatch_action_required', [
            'title' => $isPortal
                ? 'Supplier PO is available in the portal'
                : 'Supplier PO needs manual transmission',
            'message' => $isPortal
                ? "PO {$purchaseOrder->po_number} is available to the supplier portal. Confirm transmission after the supplier is actually notified."
                : "PO {$purchaseOrder->po_number} has no active supplier portal recipient. Send the approved PO through an approved channel and confirm transmission.",
            'link_to' => "/purchasing/purchase-orders/{$purchaseOrder->hash_id}",
            'entity_type' => 'purchase_order',
            'entity_id' => $purchaseOrder->hash_id,
        ]);
    }

    private function recordFailure(int $dispatchId, Throwable $exception): void
    {
        try {
            SupplierOrderDispatch::query()
                ->whereKey($dispatchId)
                ->where('status', SupplierDispatchStatus::Pending->value)
                ->update([
                    'status' => SupplierDispatchStatus::Failed->value,
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // The queue lifecycle ledger still records the failed listener if
            // the dispatch row itself cannot be updated.
        }
    }

    private function idempotencyKey(PurchaseOrder $purchaseOrder): string
    {
        return 'purchase-order:'.$purchaseOrder->id.':approved:v1';
    }

    private function recoverOne(SupplierOrderDispatch $dispatch, bool $retryFailed): string
    {
        $purchaseOrder = PurchaseOrder::withTrashed()->find($dispatch->purchase_order_id);

        if (! $purchaseOrder || $purchaseOrder->trashed() || $purchaseOrder->status === PurchaseOrderStatus::Cancelled) {
            $this->cancelDispatch(
                $dispatch->purchase_order_id,
                'Purchase order is cancelled or deleted; supplier dispatch was closed without retrying.',
            );

            return 'cancelled';
        }

        if (in_array($purchaseOrder->status, [
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::PartiallyReceived,
            PurchaseOrderStatus::Received,
            PurchaseOrderStatus::Closed,
        ], true)) {
            $this->confirmSent($purchaseOrder, $dispatch->channel ?? 'reconciled');

            return 'confirmed';
        }

        if ($purchaseOrder->status !== PurchaseOrderStatus::Approved) {
            $this->cancelDispatch(
                $purchaseOrder->id,
                'Purchase order is no longer approved; supplier dispatch was closed without transmission.',
            );

            return 'cancelled';
        }

        if ($dispatch->status === SupplierDispatchStatus::Failed && ! $retryFailed) {
            return 'skipped';
        }

        $recovered = $this->prepareForApproved($purchaseOrder->fresh() ?? $purchaseOrder);

        return $recovered === null ? 'skipped' : 'recovered';
    }

    private function cancelDispatch(int $purchaseOrderId, string $reason): ?SupplierOrderDispatch
    {
        return DB::transaction(function () use ($purchaseOrderId, $reason): ?SupplierOrderDispatch {
            $dispatch = SupplierOrderDispatch::query()
                ->where('purchase_order_id', $purchaseOrderId)
                ->lockForUpdate()
                ->first();

            if ($dispatch === null) {
                return null;
            }

            if ($dispatch->status === SupplierDispatchStatus::Cancelled) {
                return $dispatch->fresh();
            }

            $metadata = is_array($dispatch->metadata) ? $dispatch->metadata : [];
            $metadata['cancelled_by_process'] = true;
            $metadata['cancellation_reason'] = $reason;

            $dispatch->forceFill([
                'status' => SupplierDispatchStatus::Cancelled,
                'last_error' => mb_substr($reason, 0, 2000),
                'metadata' => $metadata,
            ])->save();

            return $dispatch->fresh();
        });
    }
}
