<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Services\NotificationService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\SalesOrderResponseStatus;
use App\Modules\CRM\Enums\SalesOrderResponseType;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Mail\CustomerSalesOrderDecisionMail;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderResponse;
use App\Modules\CRM\Models\SalesOrderResponseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Customer response / negotiation lifecycle for sales orders.
 *
 * Two halves, the same split as PurchaseOrderResponse on the supplier side:
 *
 * `respond()` is the customer-side entry point (B2B portal). It records the
 * customer's reply. `accept` resolves immediately; `propose`/`decline` stay
 * pending for the internal sales team. The sales order stays in `draft` — the
 * forward-only status enum is deliberately untouched and a customer `accept`
 * never trips MRP; internal sales still calls `confirm()`.
 *
 * `resolve()` is the internal entry point (`crm.sales_orders.confirm` holders).
 * Accepting a `propose` applies the counter-offer to the order lines, recomputes
 * the money, and re-runs the confirmation credit gate. Rejecting leaves the
 * order untouched so the customer can respond again.
 */
class SalesOrderResponseService
{
    /** Only a draft order that was explicitly released to the customer is negotiable. */
    private const RESPONDABLE_STATUSES = [
        SalesOrderStatus::Draft,
    ];

    public function __construct(
        private readonly SalesOrderService $salesOrders,
        private readonly TaxPolicyService $taxPolicy,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Record a customer's reply to a sales order released for review.
     *
     * @param  array{type: string, proposed_delivery_date?: string|null, notes?: string|null, items?: array<int, array{sales_order_item_id: string, proposed_quantity?: string, proposed_unit_price?: string, reason?: string|null}>}  $data
     */
    public function respond(
        SalesOrder $so,
        int $customerId,
        ?int $portalUserId,
        array $data,
    ): SalesOrderResponse {
        return DB::transaction(function () use ($so, $customerId, $portalUserId, $data): SalesOrderResponse {
            $row = SalesOrder::query()->lockForUpdate()->findOrFail($so->id);
            if ((int) $row->customer_id !== $customerId) {
                throw new ForbiddenActionException('This sales order belongs to another customer.');
            }
            if (! in_array($row->status, self::RESPONDABLE_STATUSES, true)
                || $row->customer_confirmation_requested_at === null) {
                throw new BusinessRuleException('This sales order is not open to customer response.');
            }

            $type = SalesOrderResponseType::tryFrom((string) $data['type']);
            if ($type === null) {
                throw new BusinessRuleException('Invalid customer response type.');
            }

            // A re-submission replaces the prior pending reply. The old row is
            // kept for the audit trail; only the newest pending response is
            // actionable by internal sales.
            SalesOrderResponse::query()
                ->where('sales_order_id', $row->id)
                ->where('status', SalesOrderResponseStatus::Pending)
                ->update(['status' => SalesOrderResponseStatus::Superseded->value]);

            $response = new SalesOrderResponse([
                'sales_order_id'         => $row->id,
                'customer_id'            => $row->customer_id,
                'portal_user_id'         => $portalUserId,
                'response_type'          => $type->value,
                'status'                 => $type === SalesOrderResponseType::Accept
                    ? SalesOrderResponseStatus::Accepted->value
                    : SalesOrderResponseStatus::Pending->value,
                'proposed_delivery_date' => $data['proposed_delivery_date'] ?? null,
                'notes'                  => $data['notes'] ?? null,
                'responded_at'           => now(),
            ]);
            $response->save();

            if ($type === SalesOrderResponseType::Propose) {
                $this->storeProposedItems($response, $row, $data['items'] ?? []);
            }

            $this->notifySalesAudience($row->fresh(), $response);

            return $response->load('items');
        });
    }

    /**
     * Internal sales decides a pending response.
     *
     * @param  string  $decision  `accept` | `reject`
     */
    public function resolve(
        SalesOrderResponse $response,
        User $by,
        string $decision,
        ?string $notes = null,
    ): SalesOrderResponse {
        return DB::transaction(function () use ($response, $by, $decision, $notes): SalesOrderResponse {
            $locked = SalesOrderResponse::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($response->id);
            if ($locked->status !== SalesOrderResponseStatus::Pending) {
                throw new BusinessRuleException('Only a pending customer response can be resolved.');
            }

            $so = SalesOrder::query()->lockForUpdate()->findOrFail($locked->sales_order_id);

            if ($decision === 'accept') {
                if ($locked->response_type === SalesOrderResponseType::Propose) {
                    // Apply the counter-offer under the row locks, then re-run
                    // the same credit gate confirm() enforces before the order
                    // can be confirmed.
                    $this->applyProposal($locked, $so);
                    $this->salesOrders->assertCreditWithinLimit($so);
                }
                // Accepting a `decline` changes nothing on the order; the reply
                // itself is accepted so the trail shows the decision was made.
                $locked->status = SalesOrderResponseStatus::Accepted;
            } else {
                // Rejection is a "try again": the order is untouched so the
                // customer can file a fresh reply (also true for a decline).
                $locked->status = SalesOrderResponseStatus::Rejected;
            }

            $locked->resolved_by      = $by->id;
            $locked->resolved_at      = now();
            $locked->resolution_notes = $notes;
            $locked->save();

            if ($so->isDirty()) {
                $so->save();
            }

            $this->emailCustomerDecision($locked->load('items', 'salesOrder'), $decision);

            return $locked->fresh()->load('items');
        });
    }

    /**
     * @param  array<int, array{sales_order_item_id: string, proposed_quantity?: string, proposed_unit_price?: string, reason?: string|null}>  $items
     */
    private function storeProposedItems(SalesOrderResponse $response, SalesOrder $so, array $items): void
    {
        $lineIds = SalesOrderItem::query()
            ->where('sales_order_id', $so->id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        foreach ($items as $item) {
            $itemId = HashIdFilter::decode($item['sales_order_item_id'] ?? '', SalesOrderItem::class)
                ?? (int) ($item['sales_order_item_id'] ?? 0);
            if ($itemId === 0 || ! $lineIds->has($itemId)) {
                throw new BusinessRuleException('Each proposed line must reference an item on the sales order.');
            }

            SalesOrderResponseItem::create([
                'sales_order_response_id' => $response->id,
                'sales_order_item_id'     => $itemId,
                'proposed_quantity'       => $item['proposed_quantity'] ?? null,
                'proposed_unit_price'     => $item['proposed_unit_price'] ?? null,
                'reason'                  => $item['reason'] ?? null,
            ]);
        }
    }

    /**
     * Write a customer's counter-offer onto the sales order and recompute every
     * money column with string decimal math. Sums ALL lines (not only the
     * proposed ones) so a partial counter-offer cannot understate the subtotal.
     * Runs under the response and SO row locks held by the caller.
     */
    private function applyProposal(SalesOrderResponse $response, SalesOrder $so): void
    {
        $lines = $so->items()->lockForUpdate()->get()->keyBy('id');
        $proposals = $response->items->keyBy('sales_order_item_id');
        $subtotal = Money::zero();

        foreach ($proposals as $proposal) {
            if (! $lines->has($proposal->sales_order_item_id)) {
                throw new BusinessRuleException('A proposed line no longer matches a sales-order item.');
            }
        }

        foreach ($lines as $line) {
            $proposal = $proposals->get($line->id);

            if ($proposal !== null && $proposal->proposed_quantity !== null) {
                if (Money::lte((string) $proposal->proposed_quantity, '0')) {
                    throw new BusinessRuleException('Proposed quantity must be greater than zero.');
                }
                $line->quantity = $proposal->proposed_quantity;
            }
            if ($proposal !== null && $proposal->proposed_unit_price !== null) {
                if (Money::lte((string) $proposal->proposed_unit_price, '0')) {
                    throw new BusinessRuleException('Proposed unit price must be greater than zero.');
                }
                $line->unit_price = $proposal->proposed_unit_price;
            }

            $line->total = Money::mul((string) $line->quantity, (string) $line->unit_price);
            $line->save();
            $subtotal = Money::add($subtotal, (string) $line->total);
        }

        $isVatable = $this->taxPolicy->isVatRegistered();
        $so->subtotal = $subtotal;
        $so->vat_amount = $isVatable
            ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate())
            : Money::zero();
        $so->total_amount = Money::add($so->subtotal, $so->vat_amount);
    }

    private function notifySalesAudience(SalesOrder $so, SalesOrderResponse $response): void
    {
        try {
            $audience = User::query()
                ->where('is_active', true)
                ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'crm.sales_orders.confirm'))
                ->get();

            $this->notifications->send($audience, 'customer.so_responded', [
                'title'       => "Sales order {$so->so_number} — customer {$response->response_type->label()}",
                'message'     => $this->responseSummary($response),
                'link_to'     => "/crm/sales-orders/{$so->hash_id}",
                'entity_type' => 'sales_order',
                'entity_id'   => $so->hash_id,
                'so_number'   => $so->so_number,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SalesOrderResponseService::notifySalesAudience failed', ['error' => $e->getMessage()]);
        }
    }

    private function responseSummary(SalesOrderResponse $response): string
    {
        return match ($response->response_type) {
            SalesOrderResponseType::Accept  => 'The customer accepted the sales order as ordered.',
            SalesOrderResponseType::Propose => 'The customer proposed changes to the order — review and accept or reject.',
            SalesOrderResponseType::Decline => 'The customer declined the sales order — decide whether to cancel or renegotiate.',
        };
    }

    private function emailCustomerDecision(SalesOrderResponse $response, string $decision): void
    {
        try {
            $so = $response->salesOrder;
            $recipients = CustomerPortalUser::query()
                ->where('customer_id', $response->customer_id)
                ->where('is_active', true)
                ->pluck('email')
                ->unique()
                ->values()
                ->all();
            if ($recipients === []) {
                $customerEmail = $so?->customer?->email;
                if (! is_string($customerEmail) || trim($customerEmail) === '') {
                    // No portal users and no customer email — the decision is
                    // already recorded on the response row; nothing to send.
                    return;
                }
                $recipients = [$customerEmail];
            }

            Mail::to($recipients)->send(new CustomerSalesOrderDecisionMail($so, $response, $decision));
        } catch (\Throwable $e) {
            // Notification failure must never break the resolution transaction;
            // the decision itself is durable on the response row.
            Log::warning('SalesOrderResponseService::emailCustomerDecision failed', [
                'response_id' => $response->id,
                'decision'    => $decision,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
