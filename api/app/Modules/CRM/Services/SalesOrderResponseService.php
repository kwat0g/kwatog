<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Mail\UserNotificationMail;
use Carbon\Carbon;
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
use App\Modules\CRM\Enums\SalesOrderSubmissionSource;
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

    public function proposeDeliveryDate(SalesOrder $so, User $by, array $data): SalesOrderResponse
    {
        abort_unless($by->hasPermission('crm.sales_orders.confirm'), 403);
        return DB::transaction(function () use ($so, $by, $data): SalesOrderResponse {
            $row = SalesOrder::query()->lockForUpdate()->findOrFail($so->id);
            $this->assertDeliveryNegotiable($row);
            $date = (string) $data['proposed_delivery_date'];
            $notes = trim((string) ($data['notes'] ?? ''));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || ! Carbon::hasFormat($date, 'Y-m-d') || $date < today()->toDateString()
                || $date < $row->date->toDateString() || $notes === '' || mb_strlen($notes) > 1000) {
                throw new BusinessRuleException('Provide a valid delivery date on or after today and a reason of up to 1000 characters.');
            }
            $lines = $row->items()->lockForUpdate()->get();
            if ($lines->isEmpty() || $lines->every(fn ($line) => optional($line->delivery_date)->toDateString() === $date)) {
                throw new BusinessRuleException('Propose a delivery date different from the current schedule.');
            }
            $row->supersedeDeliveryProposals();
            $response = $row->responses()->create([
                'customer_id' => $row->customer_id, 'proposed_by' => $by->id,
                'response_type' => SalesOrderResponseType::DeliveryDate,
                'status' => SalesOrderResponseStatus::Pending,
                'proposed_delivery_date' => $date, 'notes' => $notes, 'responded_at' => now(),
            ]);
            $row->update(['status' => SalesOrderStatus::DeliveryNegotiation]);
            $this->notifications->sendPortalInApp(CustomerPortalUser::where('customer_id', $row->customer_id)->where('is_active', true)->get(), 'sales_order.delivery_proposed', [
                'title' => "Review delivery date for {$row->so_number}",
                'message' => "Sales proposes {$date}. {$notes} Accept or decline this date in your order.",
                'link_to' => '/portal/customer/orders/'.$row->hash_id,
                'entity_type' => 'sales_order', 'entity_id' => $row->hash_id,
            ], 'so:delivery:'.$response->id.':proposed');
            $emails = CustomerPortalUser::where('customer_id', $row->customer_id)->where('is_active', true)->pluck('email')->unique()
                ->filter(static fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->values()->all();
            if ($emails !== []) {
                $mail = new UserNotificationMail('sales_order.delivery_proposed', [
                    'title' => "Delivery date proposal for {$row->so_number}",
                    'message' => "Sales proposes delivery on {$date}. {$notes} Review and accept or decline in your customer portal.",
                    'link_to' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/customer/orders/'.$row->hash_id,
                ]);
                DB::afterCommit(static function () use ($emails, $mail): void {
                    try {
                        Mail::to($emails)->queue($mail);
                    } catch (\Throwable $e) {
                        Log::warning('Delivery proposal email failed', ['error' => $e->getMessage()]);
                    }
                });
            }
            return $response->load('items');
        });
    }

    public function requestCancellation(
        SalesOrder $so,
        CustomerPortalUser $by,
        ?string $reason,
    ): SalesOrderResponse {
        return DB::transaction(function () use ($so, $by, $reason): SalesOrderResponse {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $row = SalesOrder::query()->lockForUpdate()->findOrFail($so->id);
            abort_unless((int) $row->customer_id === (int) $by->customer_id, 404);
            if ($row->submission_source !== SalesOrderSubmissionSource::CustomerPortal
                || ! in_array($row->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::InProduction], true)) {
                throw new BusinessRuleException('Cancellation review is available only for your confirmed orders that are still active.');
            }

            $reason = trim((string) $reason);
            if (mb_strlen($reason) > 1000) {
                throw new BusinessRuleException('Use 1000 characters or fewer for the cancellation reason.');
            }

            $pending = $row->responses()
                ->where('response_type', SalesOrderResponseType::CancellationRequest->value)
                ->where('status', SalesOrderResponseStatus::Pending->value)
                ->latest('id')
                ->first();
            if ($pending) {
                return $pending->load('items');
            }

            $response = $row->responses()->create([
                'customer_id' => $row->customer_id,
                'portal_user_id' => $by->id,
                'response_type' => SalesOrderResponseType::CancellationRequest,
                'status' => SalesOrderResponseStatus::Pending,
                'notes' => $reason !== '' ? $reason : null,
                'responded_at' => now(),
            ]);

            $this->notifySalesAudience($row, $response);
            $this->notifications->sendPortalInApp(CustomerPortalUser::where('customer_id', $row->customer_id)->where('is_active', true)->get(), 'sales_order.cancellation_requested', [
                'title' => "Cancellation request received for {$row->so_number}",
                'message' => 'Sales will review downstream production and purchasing commitments before deciding.',
                'link_to' => '/portal/customer/orders/'.$row->hash_id,
                'entity_type' => 'sales_order',
                'entity_id' => $row->hash_id,
            ], 'so:cancellation-request:'.$response->id);

            return $response->load('items');
        });
    }

    public function respondToDeliveryProposal(SalesOrder $so, SalesOrderResponse $response, CustomerPortalUser $by, string $decision): SalesOrderResponse
    {
        return DB::transaction(function () use ($so, $response, $by, $decision): SalesOrderResponse {
            $row = SalesOrder::query()->lockForUpdate()->findOrFail($so->id);
            abort_unless((int) $row->customer_id === (int) $by->customer_id, 404);
            $this->assertDeliveryNegotiable($row);
            $locked = $row->responses()->lockForUpdate()->findOrFail($response->id);
            abort_unless((int) $locked->customer_id === (int) $by->customer_id, 404);
            if ($locked->response_type !== SalesOrderResponseType::DeliveryDate || ! in_array($decision, ['accept', 'reject'], true)) {
                throw new BusinessRuleException('Invalid delivery proposal decision.');
            }
            $status = $decision === 'accept' ? SalesOrderResponseStatus::Accepted : SalesOrderResponseStatus::Rejected;
            if ($locked->status === $status) {
                return $locked->load('items');
            }
            if ($locked->status !== SalesOrderResponseStatus::Pending) {
                throw new BusinessRuleException('This delivery proposal is no longer awaiting your response. Refresh the order.');
            }
            if ($decision === 'accept') {
                if ($locked->proposed_delivery_date->lt(today())) {
                    throw new BusinessRuleException('This proposed date has passed. Ask sales for a new date.');
                }
                foreach ($row->items()->lockForUpdate()->get() as $line) {
                    $line->requested_delivery_date ??= $line->delivery_date;
                    $line->delivery_date = $locked->proposed_delivery_date;
                    $line->save();
                }
            }
            $row->update(['status' => $decision === 'accept' ? SalesOrderStatus::AwaitingConfirmation : SalesOrderStatus::DeliveryNegotiation]);
            $locked->update(['status' => $status, 'portal_user_id' => $by->id, 'resolved_at' => now()]);
            $this->notifySalesAudience($row, $locked);
            $this->notifications->sendPortalInApp(CustomerPortalUser::where('customer_id', $row->customer_id)->where('is_active', true)->get(), 'sales_order.delivery_decided', [
                'title' => "Delivery date ".($decision === 'accept' ? 'accepted' : 'declined')." for {$row->so_number}",
                'message' => $decision === 'accept' ? 'Your agreed delivery date is saved. Sales will review and confirm the order.' : 'Your current schedule is unchanged. Sales has been notified and can propose another date.',
                'link_to' => '/portal/customer/orders/'.$row->hash_id,
                'entity_type' => 'sales_order', 'entity_id' => $row->hash_id,
            ], 'so:delivery:'.$locked->id.':'.$decision);
            return $locked->fresh()->load('items');
        });
    }

    private function assertDeliveryNegotiable(SalesOrder $so): void
    {
        if (! $so->status->isBeforeConfirmation() || $so->submission_source !== SalesOrderSubmissionSource::CustomerPortal) {
            throw new BusinessRuleException('Delivery dates can be negotiated only for unconfirmed customer-submitted orders.');
        }
    }

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

            if ($row->submission_source === SalesOrderSubmissionSource::CustomerPortal) {
                throw new BusinessRuleException('Edit or cancel your unconfirmed order directly in the customer portal.');
            }

            $type = SalesOrderResponseType::tryFrom((string) $data['type']);
            if ($type === null || in_array($type, [
                SalesOrderResponseType::DeliveryDate,
                SalesOrderResponseType::CancellationRequest,
            ], true)) {
                throw new BusinessRuleException('Invalid customer response type.');
            }

            // Accept is terminal on the customer side. A browser retry or a
            // lost response must return the same response row rather than
            // appending a second accepted decision.
            if ($type === SalesOrderResponseType::Accept) {
                $existing = SalesOrderResponse::query()
                    ->where('sales_order_id', $row->id)
                    ->where('customer_id', $row->customer_id)
                    ->where('response_type', SalesOrderResponseType::Accept->value)
                    ->where('status', SalesOrderResponseStatus::Accepted->value)
                    ->latest('id')
                    ->first();
                if ($existing) {
                    return $existing->load('items');
                }
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
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $locked = SalesOrderResponse::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($response->id);
            $permission = $locked->response_type === SalesOrderResponseType::CancellationRequest
                ? 'crm.sales_orders.cancel'
                : 'crm.sales_orders.confirm';
            if (! $by->hasPermission($permission)) {
                throw new ForbiddenActionException('You do not have permission to resolve this customer response.');
            }
            if ($locked->response_type === SalesOrderResponseType::DeliveryDate) {
                throw new BusinessRuleException('Only the customer can accept or decline a sales delivery-date proposal.');
            }
            if ($locked->status === SalesOrderResponseStatus::Accepted && $decision === 'accept') {
                if ($locked->resolved_at === null) {
                    $locked->resolved_by = $by->id;
                    $locked->resolved_at = now();
                    $locked->resolution_notes = $notes;
                    $locked->save();
                    $this->emailCustomerDecision($locked->load('items', 'salesOrder'), $decision);
                }

                return $locked->fresh()->load('items');
            }

            if ($locked->status !== SalesOrderResponseStatus::Pending) {
                throw new BusinessRuleException('Only a pending customer response can be resolved.');
            }

            $so = SalesOrder::query()->lockForUpdate()->findOrFail($locked->sales_order_id);

            if ($decision === 'accept') {
                if ($locked->response_type === SalesOrderResponseType::CancellationRequest) {
                    $this->salesOrders->cancel($so, $locked->notes ?? $notes, null, $by);
                }
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

            if ($locked->response_type !== SalesOrderResponseType::CancellationRequest || $decision !== 'accept') {
                $this->emailCustomerDecision($locked->load('items', 'salesOrder'), $decision);
            }

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
            $raw = $item['sales_order_item_id'] ?? null;

            // Customer-facing payloads carry an opaque HashID. A numeric string
            // is NOT accepted: HashIdFilter::decode() returns the integer for any
            // ctype_digit string, so the old `?? (int)` fallback let a portal
            // customer enumerate sales-order lines by raw primary key. A real
            // int is only trusted from an internal caller passing the PHP type.
            if (is_int($raw)) {
                $itemId = $raw;
            } elseif (is_string($raw) && $raw !== '' && ! ctype_digit($raw)) {
                $itemId = SalesOrderItem::tryDecodeHash($raw);
            } else {
                $itemId = null;
            }

            if ($itemId === null || ! $lineIds->has($itemId)) {
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
        if ($so->submission_source === SalesOrderSubmissionSource::CustomerPortal) {
            throw new BusinessRuleException('Sales cannot apply commercial changes to a customer-submitted order.');
        }
        $lines = $so->items()->with('product')->lockForUpdate()->get()->keyBy('id');
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
                app(PriceAgreementService::class)->assertPriceFloor($line->product, (string) $proposal->proposed_unit_price, 'proposed_unit_price');
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
            $audience = $response->response_type === SalesOrderResponseType::CancellationRequest
                ? $this->notifications->usersWithPermission('crm.sales_orders.cancel')
                : User::query()
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
            SalesOrderResponseType::DeliveryDate => 'The customer '.($response->status === SalesOrderResponseStatus::Accepted ? 'accepted' : 'declined').' the proposed delivery date '.optional($response->proposed_delivery_date)->toDateString().'.',
            SalesOrderResponseType::CancellationRequest => 'The customer requested cancellation. Review production and purchasing commitments before deciding.',
        };
    }

    private function emailCustomerDecision(SalesOrderResponse $response, string $decision): void
    {
        try {
            $so = $response->salesOrder;
            $this->notifications->sendPortalInApp(CustomerPortalUser::where('customer_id', $response->customer_id)->where('is_active', true)->get(), 'sales_order.response_decided', [
                'title' => "Sales reviewed {$so->so_number}", 'message' => 'Sales '.($decision === 'accept' ? 'accepted' : 'rejected').' your response. Open the order to review the decision and next steps.',
                'link_to' => '/portal/customer/orders/'.$so->hash_id, 'entity_type' => 'sales_order', 'entity_id' => $so->hash_id,
            ], 'so:response:'.$response->id.':'.$decision);
            $recipients = CustomerPortalUser::query()
                ->where('customer_id', $response->customer_id)
                ->where('is_active', true)
                ->pluck('email')
                ->map(static fn ($email): string => trim((string) $email))
                ->filter(static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->unique(static fn (string $email): string => strtolower($email))
                ->values()
                ->all();
            if ($recipients === []) {
                $customerEmail = trim((string) ($so?->customer?->email ?? ''));
                if (! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                    app(\App\Common\Services\EmailDeliveryFailureNotifier::class)->notifyPermission(
                        'crm.sales_orders.confirm',
                        'Customer sales order decision',
                        "The decision for sales order {$so->so_number} was recorded, but no customer recipient has a usable email address.",
                        [
                            'link_to' => '/crm/sales-orders/'.$so->hash_id,
                            'entity_type' => 'sales_order',
                            'entity_id' => $so->hash_id,
                            'reason' => 'No eligible customer portal or customer email address was available.',
                        ],
                    );

                    return;
                }
                $recipients = [$customerEmail];
            }

            $recipients = array_values(array_unique(array_filter(
                $recipients,
                static fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
            )));
            if ($recipients === []) {
                return;
            }

            Mail::to($recipients)->queue(new CustomerSalesOrderDecisionMail($so, $response, $decision));
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
