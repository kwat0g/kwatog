<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Services\BusinessPolicyService;
use App\Common\Services\NotificationService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseType;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Mail\SupplierPoDecisionMail;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use App\Modules\Purchasing\Models\PurchaseOrderResponseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Supplier response / negotiation lifecycle.
 *
 * Two halves, deliberately separated:
 *
 * `respond()` is the supplier-side entry point (B2B portal). It records the
 * supplier's reply and moves the PO to the matching response state:
 * accept → acknowledged, propose → supplier_proposed, decline →
 * supplier_declined. Purchasing is notified so the reply is acted on.
 *
 * `resolve()` is the internal entry point (purchasing.po.approve holders).
 * Accepting a `propose` applies the counter-offer to the PO lines and
 * recomputes the money; rejecting returns the PO to `sent` so the supplier
 * can respond again. Everything financial runs under `lockForUpdate()`.
 */
class SupplierResponseService
{
    /**
     * PO statuses the supplier may respond to. A received/closed/cancelled
     * PO is no longer negotiable, and a draft/pending/approved PO has not
     * been transmitted yet.
     */
    private const RESPONDABLE_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::Acknowledged,
        PurchaseOrderStatus::SupplierProposed,
        PurchaseOrderStatus::SupplierDeclined,
    ];

    public function __construct(
        private readonly BusinessPolicyService $businessPolicy,
        private readonly TaxPolicyService $taxPolicy,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Record a supplier's reply and transition the PO.
     *
     * @param  array{type: string, proposed_delivery_date?: string|null, notes?: string|null, items?: array<int, array{purchase_order_item_id: string, proposed_quantity?: string, proposed_unit_price?: string, reason?: string|null}>}  $data
     */
    public function respond(
        PurchaseOrder $po,
        int $vendorId,
        ?int $portalUserId,
        array $data,
    ): PurchaseOrderResponse {
        return DB::transaction(function () use ($po, $vendorId, $portalUserId, $data): PurchaseOrderResponse {
            $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if ((int) $row->vendor_id !== $vendorId) {
                throw new ForbiddenActionException('This purchase order belongs to another supplier.');
            }
            if (! in_array($row->status, self::RESPONDABLE_STATUSES, true)) {
                throw new BusinessRuleException('This purchase order is not open to supplier response.');
            }

            $type = PurchaseOrderResponseType::tryFrom((string) $data['type']);
            if ($type === null) {
                throw new BusinessRuleException('Invalid supplier response type.');
            }

            // A re-submission replaces the prior pending reply. The old row is
            // kept for the audit trail; only the newest pending response is
            // actionable by purchasing.
            PurchaseOrderResponse::query()
                ->where('purchase_order_id', $row->id)
                ->where('status', PurchaseOrderResponseStatus::Pending)
                ->update(['status' => PurchaseOrderResponseStatus::Superseded->value]);

            $response = new PurchaseOrderResponse([
                'purchase_order_id'      => $row->id,
                'vendor_id'              => $row->vendor_id,
                'portal_user_id'         => $portalUserId,
                'response_type'          => $type->value,
                'status'                 => $type === PurchaseOrderResponseType::Accept
                    ? PurchaseOrderResponseStatus::Accepted->value
                    : PurchaseOrderResponseStatus::Pending->value,
                'proposed_delivery_date' => $data['proposed_delivery_date'] ?? null,
                'notes'                  => $data['notes'] ?? null,
                'responded_at'           => now(),
            ]);
            $response->save();

            if ($type === PurchaseOrderResponseType::Propose) {
                $this->storeProposedItems($response, $row, $data['items'] ?? []);
            }

            // Transition the PO to the state that describes the reply. An
            // accept resolves immediately (no purchasing decision needed); the
            // supplier's agreed date lands on `confirmed_delivery_date`, never
            // on OGAMI's `expected_delivery_date`.
            if ($type === PurchaseOrderResponseType::Accept) {
                if (! empty($data['proposed_delivery_date'])) {
                    $row->confirmed_delivery_date = $data['proposed_delivery_date'];
                }
                $row->status = PurchaseOrderStatus::Acknowledged;
            } elseif ($type === PurchaseOrderResponseType::Propose) {
                $row->status = PurchaseOrderStatus::SupplierProposed;
            } else {
                $row->status = PurchaseOrderStatus::SupplierDeclined;
            }
            $row->save();

            $this->broadcastChain($row->fresh());
            $this->notifyPurchasingAudience($row->fresh(), $response);

            return $response->load('items');
        });
    }

    /**
     * Purchasing decides a pending response.
     *
     * @param  string  $decision  `accept` | `reject`
     */
    public function resolve(
        PurchaseOrderResponse $response,
        User $by,
        string $decision,
        ?string $notes = null,
    ): PurchaseOrderResponse {
        return DB::transaction(function () use ($response, $by, $decision, $notes): PurchaseOrderResponse {
            $locked = PurchaseOrderResponse::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($response->id);
            if ($locked->status !== PurchaseOrderResponseStatus::Pending) {
                throw new BusinessRuleException('Only a pending supplier response can be resolved.');
            }

            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);

            if ($decision === 'accept') {
                if ($locked->response_type === PurchaseOrderResponseType::Propose) {
                    // Apply the counter-offer to the PO lines and recompute the
                    // money under the row locks. This is the one place a
                    // supplier proposal becomes a committed order.
                    $this->applyProposal($locked, $po);
                    if ($locked->proposed_delivery_date !== null) {
                        $po->confirmed_delivery_date = $locked->proposed_delivery_date;
                    }
                    $po->status = PurchaseOrderStatus::Acknowledged;
                } else {
                    // Accepting a `decline` does not resurrect the order: the
                    // PO stays supplier_declined so purchasing explicitly
                    // cancels or re-sources it. The reply itself is accepted so
                    // the trail shows the decision was made.
                    $po->status = PurchaseOrderStatus::SupplierDeclined;
                }
                $locked->status = PurchaseOrderResponseStatus::Accepted;
            } else {
                // Rejection is a "try again": the PO returns to `sent` so the
                // supplier can file a fresh reply (also true when the reply was
                // a decline — a reject means reconsider).
                $locked->status = PurchaseOrderResponseStatus::Rejected;
                $po->status = PurchaseOrderStatus::Sent;
            }

            $locked->resolved_by     = $by->id;
            $locked->resolved_at     = now();
            $locked->resolution_notes = $notes;
            $locked->save();

            $po->save();

            $this->broadcastChain($po->fresh());
            $this->emailSupplierDecision($locked->load('purchaseOrder'), $decision);

            return $locked->fresh()->load('items');
        });
    }

    /**
     * @param  array<int, array{purchase_order_item_id: string, proposed_quantity?: string, proposed_unit_price?: string, reason?: string|null}>  $items
     */
    private function storeProposedItems(PurchaseOrderResponse $response, PurchaseOrder $po, array $items): void
    {
        $lineIds = PurchaseOrderItem::query()
            ->where('purchase_order_id', $po->id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        foreach ($items as $item) {
            $itemId = HashIdFilter::decode($item['purchase_order_item_id'] ?? '', PurchaseOrderItem::class)
                ?? (int) ($item['purchase_order_item_id'] ?? 0);
            if ($itemId === 0 || ! $lineIds->has($itemId)) {
                throw new BusinessRuleException('Each proposed line must reference an item on the purchase order.');
            }

            PurchaseOrderResponseItem::create([
                'purchase_order_response_id' => $response->id,
                'purchase_order_item_id'     => $itemId,
                'proposed_quantity'          => $item['proposed_quantity'] ?? null,
                'proposed_unit_price'        => $item['proposed_unit_price'] ?? null,
                'reason'                     => $item['reason'] ?? null,
            ]);
        }
    }

    /**
     * Write a supplier's counter-offer onto the purchase order and recompute
     * every money column with string decimal math. Runs under both the
     * response and PO row locks held by the caller's transaction.
     */
    private function applyProposal(PurchaseOrderResponse $response, PurchaseOrder $po): void
    {
        $linesByLine = $po->items()->lockForUpdate()->get()->keyBy('id');
        $subtotal = Money::zero();

        foreach ($response->items as $item) {
            $line = $linesByLine->get($item->purchase_order_item_id);
            if (! $line) {
                throw new BusinessRuleException('A proposed line no longer matches a purchase-order item.');
            }

            if ($item->proposed_quantity !== null) {
                if (Money::lte((string) $item->proposed_quantity, '0')) {
                    throw new BusinessRuleException('Proposed quantity must be greater than zero.');
                }
                $line->quantity = $item->proposed_quantity;
            }
            if ($item->proposed_unit_price !== null) {
                if (Money::lte((string) $item->proposed_unit_price, '0')) {
                    throw new BusinessRuleException('Proposed unit price must be greater than zero.');
                }
                $line->unit_price = $item->proposed_unit_price;
            }

            $line->total = Money::mul((string) $line->quantity, (string) $line->unit_price);
            $line->save();
            $subtotal = Money::add($subtotal, (string) $line->total);
        }

        $po->subtotal = $subtotal;
        $po->vat_amount = $po->is_vatable
            ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate())
            : Money::zero();
        $po->total_amount = Money::add($po->subtotal, $po->vat_amount);
        // Same threshold + comparison PurchaseOrderService::create uses so a
        // counter-offer that crosses the VP line re-enters the approval gate.
        $po->requires_vp_approval = (float) $po->total_amount >= $this->businessPolicy->purchaseOrderVpThreshold();
    }

    private function notifyPurchasingAudience(PurchaseOrder $po, PurchaseOrderResponse $response): void
    {
        try {
            $audience = User::query()
                ->where('is_active', true)
                ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'purchasing.po.approve'))
                ->get();

            $this->notifications->send($audience, 'supplier.po_responded', [
                'title'       => "PO {$po->po_number} — supplier {$response->response_type->label()}",
                'message'     => $this->responseSummary($response),
                'link_to'     => "/purchasing/purchase-orders/{$po->hash_id}",
                'entity_type' => 'purchase_order',
                'entity_id'   => $po->hash_id,
                'po_number'   => $po->po_number,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SupplierResponseService::notifyPurchasingAudience failed', ['error' => $e->getMessage()]);
        }
    }

    private function responseSummary(PurchaseOrderResponse $response): string
    {
        return match ($response->response_type) {
            PurchaseOrderResponseType::Accept  => 'The supplier accepted the purchase order as ordered.',
            PurchaseOrderResponseType::Propose => 'The supplier proposed changes to the order — review and accept or reject.',
            PurchaseOrderResponseType::Decline => 'The supplier declined the purchase order — decide whether to cancel or re-source.',
        };
    }

    private function emailSupplierDecision(PurchaseOrderResponse $response, string $decision): void
    {
        try {
            $po = $response->purchaseOrder;
            $recipients = SupplierPortalUser::query()
                ->where('vendor_id', $response->vendor_id)
                ->where('is_active', true)
                ->pluck('email')
                ->unique()
                ->values()
                ->all();
            if ($recipients === []) {
                $vendorEmail = $po?->vendor?->email;
                if (! is_string($vendorEmail) || trim($vendorEmail) === '') {
                    // No portal users and no vendor email — the decision is
                    // already recorded on the response row; nothing to send.
                    return;
                }
                $recipients = [$vendorEmail];
            }

            Mail::to($recipients)->send(new SupplierPoDecisionMail($po, $response, $decision));
        } catch (\Throwable $e) {
            // Notification failure must never break the resolution transaction;
            // the decision itself is durable on the response row.
            Log::warning('SupplierResponseService::emailSupplierDecision failed', [
                'response_id' => $response->id,
                'decision'    => $decision,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function broadcastChain(PurchaseOrder $po): void
    {
        app(\App\Common\Services\ChainBroadcaster::class)
            ->broadcastFor($po, $po->status?->value ?? '');
    }
}
