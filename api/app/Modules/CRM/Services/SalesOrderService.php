<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Collection as InvoiceCollection;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Enums\SalesOrderSubmissionSource;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderTransitionRejection;
use App\Modules\CRM\Support\SalesOrderTransitionResult;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\SupplyChain\Models\Delivery;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    /**
     * C-2 — Allowed SalesOrder status transitions. Each key is a current
     * status; the array is the list of statuses we permit moving INTO.
     *
     * The rule is FORWARD-ONLY, not strictly linear. Every stage may skip
     * ahead, because the O2C chain has legitimate paths that never visit an
     * intermediate stage and the mark* helpers are called from inside the
     * owning module's write transaction:
     *
     *   confirmed → delivered / partially_delivered
     *     `in_production` is only ever set by WorkOrderService::start(). An
     *     order fulfilled from finished-goods stock has no work order to
     *     start, and DeliveryService::create() deliberately does not require
     *     the SO to be in production — it only checks remaining quantity and
     *     the outgoing inspection. Refusing this made confirming such a
     *     delivery throw and roll back the whole delivery confirmation.
     *
     *   confirmed / in_production / partially_delivered → invoiced
     *     InvoiceService::finalize() calls markInvoiced() *after* posting the
     *     journal entry, inside the same transaction. Refusing the transition
     *     therefore rolls back a posted JE, which makes it impossible to bill
     *     a partial delivery or raise an advance invoice.
     *
     *   invoiced → delivered / partially_delivered
     *     Billing is not the physical terminal state. finalize() promotes the
     *     SO on the FIRST finalized invoice, and per-delivery draft invoices
     *     mean a partially-billed order usually still has goods to ship. When
     *     `invoiced` was terminal, confirming the next delivery threw inside
     *     DeliveryService::confirm()'s transaction and rolled back the whole
     *     confirmation — the delivery was stuck at `delivered` forever with
     *     no path forward (CR-01 / SC-01).
     *
     * Backwards transitions remain absent — `cancelled` and `closed` are
     * terminal states — and an illegal transition is a hard error (see
     * transitionOrFail) so the owning operation rolls back rather than
     * succeeding while the SO goes stale.
     *
     * `cancelled` is not a target here on purpose: no mark* helper requests
     * it. Cancellation goes through cancel(), which has its own downstream
     * reconciliation guards (assertCancellableDownstreamState).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'confirmed' => ['in_production', 'partially_delivered', 'delivered', 'invoiced'],
        'in_production' => ['partially_delivered', 'delivered', 'invoiced'],
        'partially_delivered' => ['delivered', 'invoiced', 'paid', 'closed'],
        'delivered' => ['invoiced', 'paid', 'closed'],
        'invoiced' => ['confirmed', 'partially_delivered', 'delivered', 'paid', 'closed'],
        'paid' => ['partially_delivered', 'delivered', 'closed'],
        'closed' => [],
        'cancelled' => [],
        'draft' => [],
        'awaiting_confirmation' => [],
        'delivery_negotiation' => [],
    ];

    /** @return array<string, list<string>> */
    public static function allowedTransitions(): array
    {
        return self::ALLOWED_TRANSITIONS;
    }

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PriceAgreementService $prices,
        private readonly TaxPolicyService $taxPolicy,
    ) {}

    /**
     * Enforce customer credit limit before confirming a sales order.
     *
     * Total exposure = AR balance on open invoices (finalized/partial)
     *                + total_amount of open SOs (confirmed/in_production)
     *                + this SO's total_amount
     *
     * If exposure > credit_limit a ValidationException is thrown (422).
     * A null or zero credit_limit means no limit is enforced.
     */
    private function checkCreditLimit(SalesOrder $so): void
    {
        $customer = $so->customer ?? $so->load('customer')->customer;
        $limit = (string) ($customer->credit_limit ?? '0');
        if (bccomp($limit, '0', 2) <= 0) {
            return; // null or 0 = no limit enforced
        }

        $arBalance = (string) (Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['finalized', 'partial'])
            ->sum('balance') ?? '0');

        $openSoExposure = (string) (SalesOrder::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [
                SalesOrderStatus::Confirmed->value,
                SalesOrderStatus::InProduction->value,
            ])
            ->where('id', '!=', $so->id)
            ->sum('total_amount') ?? '0');

        $totalExposure = bcadd(
            bcadd($arBalance, $openSoExposure, 2),
            (string) $so->total_amount,
            2
        );

        if (bccomp($totalExposure, $limit, 2) > 0) {
            $msg = sprintf(
                'Credit limit exceeded. Limit: %s, Current exposure: %s (AR %s + open SOs %s + this SO %s).',
                app(CurrencyDisplayService::class)->format($limit),
                app(CurrencyDisplayService::class)->format($totalExposure),
                app(CurrencyDisplayService::class)->format($arBalance),
                app(CurrencyDisplayService::class)->format($openSoExposure),
                app(CurrencyDisplayService::class)->format($so->total_amount),
            );
            throw ValidationException::withMessages([
                'credit_limit' => [$msg],
            ]);
        }
    }

    /**
     * Re-run the confirmation credit gate against an order whose lines and
     * totals are about to change (a customer counter-offer accepted by sales).
     *
     * `checkCreditLimit` reads `$so->total_amount` and open AR/SO exposure, so
     * the caller MUST have applied the proposed values first and hold the SO
     * row lock. Locks the customer row in the same order as `confirm()` so two
     * concurrent confirmations/acceptances cannot both pass against the same
     * open exposure.
     */
    public function assertCreditWithinLimit(SalesOrder $so): void
    {
        $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($so->customer_id);
        $so->setRelation('customer', $customer);
        $this->checkCreditLimit($so);
    }

    private function assertActiveCustomer(int $customerId): void
    {
        if (! Customer::query()->active()->whereKey($customerId)->exists()) {
            throw ValidationException::withMessages([
                'customer_id' => ['The selected customer is not active or no longer exists.'],
            ]);
        }
    }

    private function assertActiveProduct(int $productId, string $errorKey): void
    {
        if (! Product::query()->active()->whereKey($productId)->exists()) {
            throw ValidationException::withMessages([
                $errorKey => ['The selected product is not active or no longer exists.'],
            ]);
        }
    }

    /**
     * Validate references again inside the write transaction. FormRequest
     * validation happens before the transaction and cannot protect against a
     * product/customer being deactivated between validation and persistence.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertActiveOrderReferences(int $customerId, array $items): void
    {
        $this->assertActiveCustomer($customerId);
        foreach ($items as $idx => $item) {
            $this->assertActiveProduct((int) $item['product_id'], "items.{$idx}.product_id");
        }
    }

    /**
     * Serialize sales-order writes with product archival and pricing writes.
     * Product archive policy depends on the absence of order lines, so the
     * reference rows must be locked before the active checks and line inserts.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function lockOrderReferences(int $customerId, array $items): void
    {
        $productIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['product_id'],
            $items,
        )));
        sort($productIds);

        if ($productIds !== []) {
            Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        Customer::query()
            ->whereKey($customerId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertDeliveryDatesNotBeforeOrder(Carbon $orderDate, array $items): void
    {
        foreach ($items as $idx => $item) {
            $deliveryDate = Carbon::parse((string) $item['delivery_date']);
            if ($deliveryDate->lt($orderDate)) {
                throw ValidationException::withMessages([
                    "items.{$idx}.delivery_date" => ['The delivery date must be on or after the sales order date.'],
                ]);
            }
        }
    }

    /**
     * Cancellation is intentionally narrower than a status transition. Active
     * delivery, billing, production, or purchasing commitments need review.
     */
    private function assertCancellableDownstreamState(SalesOrder $so): void
    {
        $blocker = $this->cancellationBlocker($so, true);
        if ($blocker !== null) {
            throw new BusinessRuleException($blocker);
        }
    }

    public function canCancel(SalesOrder $so): bool
    {
        return $this->cancellationBlocker($so) === null;
    }

    public function cancellationBlocker(SalesOrder $so, bool $lockPurchasing = false): ?string
    {
        if (! $so->status->isBeforeConfirmation() && $so->status !== SalesOrderStatus::Confirmed) {
            return 'This sales order cannot be cancelled at its current status.';
        }

        $deliveryService = app(\App\Modules\SupplyChain\Services\DeliveryService::class);
        foreach (Delivery::query()->where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->get() as $delivery) {
            if (! $deliveryService->isUntouchedAutomaticSupply($delivery)) {
                return 'This sales order cannot be cancelled while a delivery is active.';
            }
        }

        if (Invoice::query()->where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->exists()) {
            return 'This sales order cannot be cancelled while an invoice is active.';
        }

        if (WorkOrder::query()
            ->where('sales_order_id', $so->id)
            ->where(function ($query): void {
                $query->whereIn('status', ['in_progress', 'paused', 'completed', 'closed'])
                    ->orWhereNotNull('actual_start')
                    ->orWhere('quantity_produced', '>', 0)
                    ->orWhereHas('outputs')
                    ->orWhereHas('materials', fn ($materials) => $materials->where('actual_quantity_issued', '>', 0));
            })
            ->exists()) {
            return 'This sales order cannot be cancelled after production has started or material has been issued.';
        }

        $purchaseRequests = PurchaseRequest::query()
            ->where('is_auto_generated', true)
            ->whereHas('mrpPlan', fn ($query) => $query->where('sales_order_id', $so->id))
            ->whereNotIn('status', ['cancelled', 'rejected']);
        if ($lockPurchasing) {
            $purchaseRequests->lockForUpdate();
        }
        foreach ($purchaseRequests->get() as $purchaseRequest) {
            if (! in_array($purchaseRequest->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Pending], true)
                || $purchaseRequest->purchaseOrders()->where('status', '!=', 'cancelled')->exists()
                || $purchaseRequest->rfqs()->whereNotIn('status', ['cancelled', 'rejected'])->exists()) {
                return 'This sales order cannot be cancelled while purchasing has committed to its materials.';
            }
        }

        return null;
    }

    private function transitionTimestamp(SalesOrderStatus $target): array
    {
        return match ($target) {
            SalesOrderStatus::Confirmed => ['confirmed_at' => now()],
            SalesOrderStatus::InProduction => ['in_production_at' => now()],
            SalesOrderStatus::PartiallyDelivered => ['partially_delivered_at' => now()],
            SalesOrderStatus::Delivered => ['delivered_at' => now()],
            SalesOrderStatus::Invoiced => ['invoiced_at' => now()],
            SalesOrderStatus::Paid => ['paid_at' => now()],
            SalesOrderStatus::Closed => ['closed_at' => now()],
            SalesOrderStatus::Cancelled => ['cancelled_at' => now()],
            default => [],
        };
    }

    public function list(array $filters): LengthAwarePaginator
    {
        $q = SalesOrder::query()
            ->with(['customer:id,name', 'creator:id,name,role_id', 'latestResponse.items'])
            ->withCount('items')->with('items.product');

        TrashedFilter::apply($q, $filters);

        if (! empty($filters['customer_id'])) {
            $cid = HashIdFilter::decode($filters['customer_id'], Customer::class);
            if ($cid) {
                $q->where('customer_id', $cid);
            }
        }
        if (! empty($filters['status'])) {
            // A single status string or an array (the delivery create form
            // offers every deliverable status at once:
            // status[]=confirmed&status[]=in_production&…).
            $statuses = is_array($filters['status']) ? array_values($filters['status']) : [$filters['status']];
            foreach ($statuses as $status) {
                if (! is_string($status) || SalesOrderStatus::tryFrom($status) === null) {
                    throw ValidationException::withMessages([
                        'status' => ['The selected status is invalid.'],
                    ]);
                }
            }
            $q->whereIn('status', $statuses);
        }
        if (($filters['signal'] ?? null) === 'awaiting_confirmation') {
            $q->awaitingConfirmation();
        }
        if (! empty($filters['date_from'])) {
            $q->whereDate('date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->whereDate('date', '<=', $filters['date_to']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('so_number', SearchOperator::like(), SearchOperator::contains($term))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', SearchOperator::like(), SearchOperator::contains($term)));
            });
        }

         $result = $q->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
        app(SalesOrderSupplyService::class)->prepareCoverage($result->getCollection());
        return $result;
    }

    public function show(SalesOrder $so): SalesOrder
    {
        // Sprint 6 audit §3.2: eager-load MRP plan + linked work orders so
        // the SO detail page can render the right-panel LinkedRecords block
        // without N+1 round-trips. mrpPlan + workOrders relations are
        // defined on the SalesOrder model (Sprint 6 Task 52).
        $so->load([
            'customer',
            'creator:id,name,role_id',
            'latestResponse.items',
            'deliveryNegotiations',
            'items.product:id,part_number,name,unit_of_measure',
            'mrpPlan:id,mrp_plan_no,version,status,shortages_found,auto_pr_count,draft_wo_count,sales_order_id',
            'workOrders:id,wo_number,product_id,status,quantity_target,quantity_produced,sales_order_id,mrp_plan_id,planned_start',
            'workOrders.product:id,part_number,name',
            'workOrders.inspections:id,inspection_number,stage,status,entity_type,entity_id,completed_at',
            'deliveries:id,delivery_number,sales_order_id,status,scheduled_date',
            'invoices:id,invoice_number,sales_order_id,status,total_amount,balance',
        ]);

        $so->setAttribute('status_history', AuditLog::query()
            ->with('user:id,name')
            ->where('model_type', $so->getMorphClass())
            ->where('model_id', $so->id)
            ->whereIn('action', ['created', 'updated'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(static function (AuditLog $entry): bool {
                $current = $entry->new_values['status'] ?? null;
                if (! is_string($current)) {
                    return false;
                }

                return $entry->action === 'created'
                    || ($entry->action === 'updated'
                        && ($entry->old_values['status'] ?? null) !== $current);
            })
            ->map(static function (AuditLog $entry): array {
                $old = $entry->old_values ?? [];
                $new = $entry->new_values ?? [];
                $status = (string) $new['status'];
                $previousStatus = $entry->action === 'updated'
                    ? (isset($old['status']) ? (string) $old['status'] : null)
                    : null;
                $comment = null;

                if ($status === SalesOrderStatus::Cancelled->value && isset($new['notes'])) {
                    $oldNotes = (string) ($old['notes'] ?? '');
                    $newNotes = (string) $new['notes'];
                    $delta = str_starts_with($newNotes, $oldNotes)
                        ? substr($newNotes, strlen($oldNotes))
                        : '';
                    $delta = trim($delta);
                    $prefix = '[Cancelled: ';
                    if (str_starts_with($delta, $prefix) && str_ends_with($delta, ']')) {
                        $value = trim(substr($delta, strlen($prefix), -1));
                        $comment = $value !== '' ? $value : null;
                    }
                }

                return [
                    'event_type' => $entry->action === 'created' ? 'created' : 'status_changed',
                    'status' => $status,
                    'status_label' => SalesOrderStatus::tryFrom($status)?->label() ?? $status,
                    'previous_status' => $previousStatus,
                    'previous_status_label' => $previousStatus !== null
                        ? (SalesOrderStatus::tryFrom($previousStatus)?->label() ?? $previousStatus)
                        : null,
                    'actor_name' => $entry->user?->name ?? match ($entry->actor_type) {
                        'system' => 'System',
                        'customer_portal' => 'Customer portal',
                        'supplier_portal' => 'Supplier portal',
                        'user' => 'User unavailable',
                        default => null,
                    },
                    'occurred_at' => $entry->created_at?->toIso8601String(),
                    'comment' => $comment,
                ];
            })
            ->values()
            ->all());

        return $so;
    }

    public function quoteOrder(int $customerId, array $items, ?SalesOrder $existing = null, bool $portal = false): array
    {
        $oldLines = $existing?->items()->get()->keyBy('product_id') ?? collect();
        $lines = [];
        $subtotal = Money::zero();
        $allUnchanged = $existing !== null && count($items) === $oldLines->count();
        foreach ($items as $index => $item) {
            $productId = (int) $item['product_id'];
            $date = Carbon::parse($item['delivery_date']);
            $quantity = bcadd((string) $item['quantity'], '0', 2);
            $old = $oldLines->get($productId);
            $unchanged = $old !== null && $existing?->customer_id === $customerId
                && Money::cmp($old->quantity, $quantity) === 0
                && in_array($date->toDateString(), [optional($old->delivery_date)->toDateString(), optional($old->requested_delivery_date)->toDateString()], true);
            if ($unchanged) {
                $price = $old->only(['unit_price', 'price_source', 'price_agreement_id', 'price_tier_min_qty']);
                $price['pricing_date'] = optional($old->pricing_date)->toDateString();
                $line = array_merge($price, [
                    'existing_id' => $old->id, 'product_id' => $productId, 'quantity' => $quantity,
                    'total' => $old->total, 'quantity_delivered' => $old->quantity_delivered,
                    'delivery_date' => optional($old->delivery_date)->toDateString(),
                    'requested_delivery_date' => optional($old->requested_delivery_date)->toDateString(),
                ]);
            } else {
                $allUnchanged = false;
                try {
                    $price = $this->prices->quote($customerId, $productId, $date, $quantity, $portal);
                } catch (ValidationException $e) {
                    $errors = [];
                    foreach ($e->errors() as $key => $messages) {
                        $errors["items.{$index}.{$key}"] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                $line = array_merge($price, [
                    'product_id' => $productId, 'quantity' => $quantity,
                    'total' => Money::mul($quantity, $price['unit_price']), 'quantity_delivered' => '0.00',
                    'delivery_date' => $date->toDateString(),
                ]);
            }
            $subtotal = Money::add($subtotal, $line['total']);
            $lines[] = $line;
        }
        $vat = $allUnchanged ? (string) $existing->vat_amount : ($this->taxPolicy->isVatRegistered()
            ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate()) : Money::zero());
        $quote = ['items' => $lines, 'subtotal' => $subtotal, 'vat_amount' => $vat, 'total_amount' => Money::add($subtotal, $vat)];
        $quote['signature'] = hash_hmac('sha256', json_encode([$customerId, $quote], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return $quote;
    }

    private function assertQuoteSignature(array $quote, ?string $signature): void
    {
        if ($signature !== null && ! hash_equals($quote['signature'], $signature)) {
            throw ValidationException::withMessages(['quote_signature' => ['Prices changed. Review the refreshed quote before submitting again.']]);
        }
    }

    /**
     * Create a draft SO. Resolves price-per-line via PriceAgreementService;
     * rejects a line if neither a valid agreement nor a base selling price is available.
     *
     * Wrapped in a transaction to keep so_number generation atomic.
     */
    public function create(array $data, int $userId): SalesOrder
    {
        return DB::transaction(function () use ($data, $userId) {
            $customerId = (int) $data['customer_id'];
            $orderDate = Carbon::parse($data['date']);
            $items = $data['items'];

            $this->lockOrderReferences($customerId, $items);
            $this->assertActiveOrderReferences($customerId, $items);
            $this->assertDeliveryDatesNotBeforeOrder($orderDate, $items);

            $quote = $this->quoteOrder($customerId, $items, null, ($data['submission_source'] ?? 'internal') === 'customer_portal');
            $this->assertQuoteSignature($quote, $data['quote_signature'] ?? null);
            $lines = $quote['items'];
            $subtotal = $quote['subtotal'];
            $vat = $quote['vat_amount'];
            $total = $quote['total_amount'];

            $so = SalesOrder::create([
                'so_number' => $this->sequences->generate('sales_order'),
                'customer_id' => $customerId,
                'date' => $orderDate->toDateString(),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total_amount' => $total,
                'status' => ($data['submission_source'] ?? 'internal') === 'customer_portal'
                    ? SalesOrderStatus::AwaitingConfirmation->value : SalesOrderStatus::Draft->value,
                'payment_terms_days' => $data['payment_terms_days']
                    ?? (int) Customer::query()->whereKey($customerId)->value('payment_terms_days'),
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'incoterm' => $data['incoterm'] ?? null,
                'submission_source' => $data['submission_source'] ?? 'internal',
                'portal_idempotency_key' => $data['portal_idempotency_key'] ?? null,
                'portal_idempotency_fingerprint' => $data['portal_idempotency_fingerprint'] ?? null,
                'created_by' => $userId,
            ]);

            // Persist lines.
            foreach ($lines as $line) {
                $so->items()->create($line + ['fulfillment_policy' => 'stock_first']);
            }

            return $this->show($so->fresh());
        });
    }

    /** Create the sole approved, zero-price order used for case redelivery. */
    public function createNoChargeReplacement(ReturnCase $case, int $userId): SalesOrder
    {
        return DB::transaction(function () use ($case, $userId): SalesOrder {
            $lockedCase = ReturnCase::query()->lockForUpdate()->findOrFail($case->id);
            $approver = User::query()->findOrFail($userId);
            abort_unless($approver->hasPermission('return_management.approve'), 403);
            if ($lockedCase->type->value !== 'customer' || $lockedCase->resolution?->value !== 'redelivery'
                || ! in_array($lockedCase->status->value, ['action_agreed', 'in_progress'], true)) {
                throw new BusinessRuleException('An agreed customer redelivery is required to authorize a no-charge replacement.');
            }
            $existing = SalesOrder::query()->where('return_case_id', $lockedCase->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('items');
            }
            $lockedCase->load('lines');
            $lines = $lockedCase->lines->filter(fn ($line): bool => bccomp((string) ($line->verified_missing_quantity ?? '0'), '0', 3) > 0
                || bccomp((string) ($line->verified_defective_quantity ?? '0'), '0', 3) > 0);
            if ($lines->isEmpty() || $lockedCase->customer_id === null) {
                throw new BusinessRuleException('Verify at least one customer quantity before preparing a no-charge replacement.');
            }
            $so = new SalesOrder;
            $so->forceFill([
                'so_number' => $this->sequences->generate('sales_order'),
                'customer_id' => $lockedCase->customer_id,
                'return_case_id' => $lockedCase->id,
                'date' => now()->toDateString(), 'subtotal' => '0.00', 'vat_amount' => '0.00', 'total_amount' => '0.00',
                'status' => SalesOrderStatus::Draft->value, 'payment_terms_days' => 0,
                'notes' => 'Approved no-charge replacement for '.$lockedCase->case_number,
                'submission_source' => 'internal', 'created_by' => $userId,
            ])->save();
            foreach ($lines as $line) {
                $quantity = bcadd((string) ($line->verified_missing_quantity ?? '0'), (string) ($line->verified_defective_quantity ?? '0'), 3);
                if ($line->product_id === null) {
                    throw new BusinessRuleException('Every replacement line must identify a product.');
                }
                if (bccomp($quantity, bcadd($quantity, '0', 2), 3) !== 0) {
                    throw new BusinessRuleException('Replacement orders support two decimal places. Review fractional quantities before authorization.');
                }
                $so->items()->create([
                    'product_id' => $line->product_id, 'quantity' => $quantity, 'unit_price' => '0.00',
                    'total' => '0.00', 'quantity_delivered' => '0', 'delivery_date' => $lockedCase->expected_date?->toDateString() ?? now()->toDateString(),
                ]);
            }
            if (! $so->items()->exists()) {
                throw new BusinessRuleException('Verified case quantities do not identify a product for replacement.');
            }

            return $so->load('items');
        });
    }

    /**
     * Update a draft SO (recreate line items). Disallowed past draft.
     */
    public function update(SalesOrder $so, array $data, ?CustomerPortalUser $customerActor = null): SalesOrder
    {
        return DB::transaction(function () use ($so, $data, $customerActor) {
            // Never trust the route-bound snapshot for the state guard. A
            // concurrent confirm/delete may have changed it after binding.
            $lockedSo = SalesOrder::query()
                ->lockForUpdate()
                ->findOrFail($so->id);
            if ($lockedSo->return_case_id !== null) {
                throw new BusinessRuleException('Approved no-charge replacement orders cannot be edited commercially. Update the linked return case through its approval workflow.');
            }
            if (! $lockedSo->status->isBeforeConfirmation()) {
                throw new BusinessRuleException('Only unconfirmed sales orders can be updated.');
            }

            $this->assertCustomerOrderMutation($lockedSo, $customerActor);

            $customerId = (int) ($data['customer_id'] ?? $lockedSo->customer_id);
            $orderDate = Carbon::parse($data['date'] ?? $lockedSo->date->toDateString());
            $items = $data['items'];

            $this->lockOrderReferences($customerId, $items);
            $this->assertActiveOrderReferences($customerId, $items);
            $this->assertDeliveryDatesNotBeforeOrder($orderDate, $items);

            $quote = $this->quoteOrder($customerId, $items, $lockedSo, $customerActor !== null);
            $this->assertQuoteSignature($quote, $data['quote_signature'] ?? null);
            $newLines = $quote['items'];
            $subtotal = $quote['subtotal'];
            $vat = $quote['vat_amount'];
            $total = $quote['total_amount'];

            $lockedSo->update([
                'customer_id' => $customerId,
                'date' => $orderDate->toDateString(),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total_amount' => $total,
                'payment_terms_days' => $data['payment_terms_days'] ?? $lockedSo->payment_terms_days,
                'delivery_terms' => array_key_exists('delivery_terms', $data)
                    ? $data['delivery_terms']
                    : $lockedSo->delivery_terms,
                'incoterm' => array_key_exists('incoterm', $data)
                    ? $data['incoterm']
                    : $lockedSo->incoterm?->value,
                'notes' => array_key_exists('notes', $data)
                    ? $data['notes']
                    : $lockedSo->notes,
            ]);

            $lockedSo->supersedeDeliveryProposals();
            if ($customerActor !== null) {
                $lockedSo->update(['status' => SalesOrderStatus::AwaitingConfirmation]);
            }
            $keepIds = [];
            foreach ($newLines as $line) {
                $existingId = $line['existing_id'] ?? null;
                unset($line['existing_id']);
                if ($existingId !== null) {
                    $keepIds[] = $existingId;
                } else {
                    $keepIds[] = $lockedSo->items()->create($line + ['fulfillment_policy' => 'stock_first'])->id;
                }
            }
            $lockedSo->items()->whereNotIn('id', $keepIds)->forceDelete();

            return $this->show($lockedSo->fresh());
        });
    }

    /**
     * Confirm a draft → 'confirmed'. Sprint 6 Task 52 will hook MRP run here.
     * For now we simply flip the status and invariant-check that the SO has lines.
     */
    public function confirm(SalesOrder $so): SalesOrder
    {
        return DB::transaction(function () use ($so) {
            // The HTTP route-bound model may be stale when a cancellation or
            // another confirmation races this request. Lock the authoritative
            // SO and customer rows before checking state or credit exposure.
            $lockedSo = SalesOrder::query()
                ->lockForUpdate()
                ->findOrFail($so->id);
            if (! $lockedSo->status->isBeforeConfirmation()) {
                throw new BusinessRuleException('Only unconfirmed sales orders can be confirmed.');
            }
            if ($lockedSo->hasPendingDeliveryProposal()) {
                throw new BusinessRuleException('Wait for the customer to accept or decline the proposed delivery date before confirming.');
            }
            if ($lockedSo->items()->count() === 0) {
                throw new BusinessRuleException('Cannot confirm a sales order with no items.');
            }

            $items = $lockedSo->items()
                ->get(['product_id'])
                ->map(static fn (SalesOrderItem $item): array => [
                    'product_id' => (int) $item->product_id,
                ])
                ->all();
            $this->lockOrderReferences((int) $lockedSo->customer_id, $items);

            // Serialize same-customer confirmations so two draft SOs cannot
            // both pass the credit check against the same open exposure.
            $customer = Customer::withTrashed()
                ->lockForUpdate()
                ->findOrFail($lockedSo->customer_id);
            $this->assertActiveOrderReferences((int) $customer->id, $items);
            $lockedSo->setRelation('customer', $customer);
            $this->checkCreditLimit($lockedSo);

            $lockedSo->update([
                'status' => SalesOrderStatus::Confirmed->value,
                ...$this->transitionTimestamp(SalesOrderStatus::Confirmed),
            ]);

            $fresh = $lockedSo->fresh();

            // Durable cross-module publication. The row is written inside the
            // same transaction as the confirmation and is enqueued only after
            // commit; the scheduler recovers a queue outage.
            app(OutboxService::class)->recordForChain(
                new SalesOrderConfirmed($fresh, actorUserId: auth()->id() === null ? null : (int) auth()->id()),
                $fresh,
                'o2c',
                'sales_order',
                SalesOrderStatus::Confirmed->value,
            );

            // Stage real-time chain progress with the confirmation. The
            // outbox dispatch itself still waits for commit, so a rollback
            // cannot leave a phantom confirmed step.
            app(ChainBroadcaster::class)->broadcastFor(
                $fresh,
                SalesOrderStatus::Confirmed->value,
                auth()->user(),
            );

            $this->notifyCustomer($fresh, 'confirmed', 'Sales confirmed your order. Production planning is the next step.');

            return $this->show($fresh);
        });
    }

    /**
     * Send a draft sales order to the customer for review, which is what makes
     * it negotiable through the portal. Confirmation still happens separately;
     * this only records that the sales team released the draft to the customer.
     */
    public function requestCustomerConfirmation(SalesOrder $so): SalesOrder
    {
        return DB::transaction(function () use ($so) {
            $lockedSo = SalesOrder::query()->lockForUpdate()->findOrFail($so->id);
            if ($lockedSo->status !== SalesOrderStatus::Draft) {
                throw new BusinessRuleException('Only draft sales orders can be sent to the customer for confirmation.');
            }
            if ($lockedSo->items()->count() === 0) {
                throw new BusinessRuleException('Cannot request customer confirmation for a sales order with no items.');
            }

            if ($lockedSo->submission_source === SalesOrderSubmissionSource::CustomerPortal) {
                throw new BusinessRuleException('Customer-submitted orders are reviewed and confirmed as submitted.');
            }
            $lockedSo->update(['customer_confirmation_requested_at' => now()]);
            $this->notifyCustomer($lockedSo, 'review_requested', 'Sales sent an order for your review. Open it to accept, propose changes, or decline.');

            return $this->show($lockedSo->fresh());
        });
    }

    /**
     * Confirm SO and return a chain summary of everything auto-created.
     *
     * Wraps confirm() which queues the durable MRP + capacity-planning job.
     * In the sync test driver the queue completes before this method returns;
     * in production the response explicitly reports planning as queued.
     */
    public function confirmWithChainResult(SalesOrder $so): array
    {
        // The confirmation outbox event queues MRP after commit. With the sync
        // test driver it has completed by now; with Redis workers it remains
        // explicitly visible as queued to the caller.
        $confirmedSo = $this->confirm($so);

        // The automatic listener may have updated mrp_plan_id after the
        // confirmation transaction returned. Refresh the foreign key before
        // loading the chain relations; otherwise an eager-loaded null
        // belongsTo relation remains pinned to the pre-MRP model snapshot.
        $confirmedSo->refresh();

        // Gather what MRP created.
        $confirmedSo->load([
            'mrpPlan.mrpRun',
            'workOrders.product:id,part_number,name',
            'workOrders.machine:id,machine_code,name',
            'workOrders.mold:id,mold_code,name',
        ]);

        $plan = $confirmedSo->mrpPlan;
        $workOrders = $confirmedSo->workOrders;

        $planSummary = (array) ($plan?->mrpRun?->summary ?? []);
        $schedulingResult = $planSummary['scheduling'] ?? ['scheduled' => [], 'conflicts' => []];

        // A missing plan is only "queued" when nothing has failed. With the
        // sync queue driver the failure has already been recorded by now, and
        // reporting it as queued told the operator to wait for a run that was
        // never coming.
        $planningFailure = $this->latestPlanningFailure((int) $confirmedSo->id);
        $planningStatus = match (true) {
            $planningFailure !== null => 'failed',
            $plan !== null => 'completed',
            default => 'queued',
        };

        // Reload WOs after scheduling may have changed their machine/mold.
        $confirmedSo->load([
            'workOrders.product:id,part_number,name',
            'workOrders.machine:id,machine_code,name',
            'workOrders.mold:id,mold_code,name',
            'workOrders.schedules',
            'workOrders.schedules.machine:id,machine_code,name',
        ]);
        $workOrders = $confirmedSo->workOrders;

        // Build per-WO summaries.
        $woSummaries = $workOrders->map(function ($wo) {
            $schedule = $wo->schedules->filter(fn ($slot) => $slot->rework_ncr_id === null
                && $slot->reserves_capacity && in_array($slot->status->value, ['pending', 'confirmed', 'executed'], true))
                ->sortByDesc('id')->first();
            $machine = $wo->machine ?? $schedule?->machine;

            return [
                'id' => $wo->hash_id,
                'wo_number' => $wo->wo_number,
                'product' => $wo->product ? [
                    'part_number' => $wo->product->part_number,
                    'name' => $wo->product->name,
                ] : null,
                'status' => $wo->status?->value,
                'quantity_target' => (int) $wo->quantity_target,
                'machine' => $machine ? $machine->machine_code.' '.$machine->name : null,
                'scheduled_start' => optional($schedule?->scheduled_start ?? $wo->planned_start)->toIso8601String(),
                'scheduled_end' => optional($schedule?->scheduled_end ?? $wo->planned_end)->toIso8601String(),
                'needs_manual_scheduling' => $wo->status?->value === 'planned' && $schedule === null,
            ];
        })->values()->all();

        // Count auto-created PRs from the MRP plan.
        $prsCreated = 0;
        $shortageCount = 0;
        if ($plan) {
            $prsCreated = (int) $plan->auto_pr_count;
            $shortageCount = (int) $plan->shortages_found;
        }

        return [
            'so' => $this->show($confirmedSo),
            'chain_result' => [
                'so_number' => $confirmedSo->so_number,
                'work_orders_created' => count($woSummaries),
                'auto_scheduled' => collect($woSummaries)->where('needs_manual_scheduling', false)->count(),
                'needs_manual' => collect($woSummaries)->where('needs_manual_scheduling', true)->count(),
                'shortages' => $shortageCount,
                'prs_created' => $prsCreated,
                'planning_status' => $planningStatus,
                'planning_error' => $planningFailure,
                'work_orders' => $woSummaries,
                'scheduling_conflicts' => $schedulingResult['conflicts'],
            ],
        ];
    }

    public function cancel(
        SalesOrder $so,
        ?string $reason = null,
        ?CustomerPortalUser $customerActor = null,
        ?User $internalActor = null,
    ): SalesOrder
    {
        return DB::transaction(function () use ($so, $reason, $customerActor, $internalActor) {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            // A stale route-bound SO must not overwrite a delivered/invoiced
            // or already-cancelled order. Re-read and lock before the guard.
            $lockedSo = SalesOrder::query()
                ->lockForUpdate()
                ->findOrFail($so->id);
            $this->assertCancellationActor($lockedSo, $customerActor, $internalActor);
            $this->assertCancellableDownstreamState($lockedSo);
            foreach (Delivery::where('sales_order_id', $lockedSo->id)->where('status', '!=', 'cancelled')->lockForUpdate()->get() as $delivery) {
                if (app(\App\Modules\SupplyChain\Services\DeliveryService::class)->isUntouchedAutomaticSupply($delivery)) {
                    app(\App\Modules\SupplyChain\Services\DeliveryService::class)->updateStatus($delivery, \App\Modules\SupplyChain\Enums\DeliveryStatus::Cancelled);
                }
            }
            $this->assertCancellableDownstreamState($lockedSo);
            app(SalesOrderSupplyService::class)->releaseUnshipped($lockedSo, \App\Modules\Auth\Models\User::findOrFail($lockedSo->created_by), $reason ?? 'Order cancelled');

            $lockedSo->update([
                'status' => SalesOrderStatus::Cancelled->value,
                ...$this->transitionTimestamp(SalesOrderStatus::Cancelled),
                'notes' => trim(($lockedSo->notes ?? '')."\n\n[Cancelled".($reason ? ': '.$reason : '').']'),
            ]);

            // Sprint 6 audit §1.2: cascade through the chain.
            //  1. Supersede the active MRP plan (status='cancelled').
            //  2. Cancel unstarted planned/confirmed WOs linked to this SO.
            //     WorkOrderService::cancel() releases each WO's reservations.
            $plan = MrpPlan::where('sales_order_id', $lockedSo->id)
                ->where('status', MrpPlanStatus::Active->value)
                ->lockForUpdate()
                ->first();
            if ($plan) {
                $plan->update(['status' => MrpPlanStatus::Cancelled->value]);
            }

            $woService = $this->workOrderService();
            if ($woService) {
                $cancellableStatuses = [
                    WorkOrderStatus::Planned->value,
                    WorkOrderStatus::Confirmed->value,
                ];
                $linkedWos = WorkOrder::query()
                    ->where('sales_order_id', $lockedSo->id)
                    ->whereIn('status', $cancellableStatuses)
                    ->lockForUpdate()
                    ->get();
                foreach ($linkedWos as $wo) {
                    $woService->cancel($wo, $reason ?? "Sales order {$lockedSo->so_number} cancelled");
                }
            }

            $lockedSo->responses()
                ->where('response_type', 'cancellation_request')
                ->where('status', 'pending')
                ->update([
                    'status' => 'accepted',
                    'resolved_by' => $internalActor?->id ?? auth()->id(),
                    'resolved_at' => now(),
                    'resolution_notes' => $reason,
                ]);

            $lockedSo->supersedeDeliveryProposals();
            $this->notifyCustomer($lockedSo, 'cancelled', 'This order was cancelled. Production and purchasing demand have been retired where cancellation is allowed.');

            // Buying demand must not outlive the order that justified it: a
            // cancelled SO used to leave its MRP auto-PR in the purchasing
            // queue, where a buyer could still source material for demand
            // that no longer exists.
            $this->retireAutoPurchaseRequests($lockedSo);

            // Series C — Task C4. Stage real-time chain progress atomically
            // with the cancellation and its downstream work-order changes.
            $fresh = $lockedSo->fresh();
            app(ChainBroadcaster::class)->broadcastFor(
                $fresh,
                SalesOrderStatus::Cancelled->value,
                auth()->user(),
            );

            return $this->show($fresh);
        });
    }

    /**
     * Resolve the production WorkOrderService through the container so that
     * the CRM module's tests can run without booting the Production module.
     */
    private function workOrderService(): ?WorkOrderService
    {
        $cls = '\\App\Modules\Production\Services\WorkOrderService';

        return class_exists($cls) ? app($cls) : null;
    }

    /**
     * Retire the automatic purchase requests raised for this SO's material
     * plans. Draft and pending only: an approved/converted PR has already
     * crossed the purchasing handoff and must be unwound by Purchasing.
     */
    private function retireAutoPurchaseRequests(SalesOrder $so): void
    {
        $autoPrs = PurchaseRequest::query()
            ->where('is_auto_generated', true)
            ->whereIn('status', [
                PurchaseRequestStatus::Draft->value,
                PurchaseRequestStatus::Pending->value,
            ])
            ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
            ->lockForUpdate()
            ->get();

        if ($autoPrs->isEmpty()) {
            return;
        }

        $service = $this->purchaseRequestService();

        foreach ($autoPrs as $pr) {
            // The service owns the cancel guard and the chain broadcast; it is
            // called actorless because the SO owner need not hold PR rights.
            if ($service !== null) {
                $service->cancel($pr);

                continue;
            }

            $pr->forceFill(['status' => PurchaseRequestStatus::Cancelled->value])->save();
        }
    }

    private function purchaseRequestService(): ?PurchaseRequestService
    {
        $cls = '\\App\Modules\Purchasing\Services\PurchaseRequestService';

        return class_exists($cls) ? app($cls) : null;
    }

    private function assertCustomerOrderMutation(SalesOrder $so, ?CustomerPortalUser $customerActor): void
    {
        if ($so->submission_source !== SalesOrderSubmissionSource::CustomerPortal) {
            if ($customerActor !== null) {
                throw new BusinessRuleException('Only orders submitted through your customer portal can be changed here.');
            }

            return;
        }
        if ($customerActor === null) {
            throw new BusinessRuleException('Customer-submitted orders can only be changed or cancelled by the customer before sales confirmation.');
        }
        abort_if($so->customer_id !== $customerActor->customer_id, 403);
        if (! $so->status->isBeforeConfirmation()) {
            throw new BusinessRuleException('Only unconfirmed customer orders can be changed or cancelled.');
        }
    }

    private function assertCancellationActor(
        SalesOrder $so,
        ?CustomerPortalUser $customerActor,
        ?User $internalActor,
    ): void {
        if ($so->submission_source !== SalesOrderSubmissionSource::CustomerPortal) {
            if ($customerActor !== null) {
                throw new BusinessRuleException('Only orders submitted through your customer portal can be changed here.');
            }

            return;
        }

        if ($customerActor !== null) {
            abort_if($so->customer_id !== $customerActor->customer_id, 403);
            if (! $so->status->isBeforeConfirmation()) {
                throw new BusinessRuleException('Confirmed orders require a cancellation review request.');
            }

            return;
        }

        if (! $internalActor?->hasPermission('crm.sales_orders.cancel')) {
            throw new BusinessRuleException('Customer-submitted orders can only be cancelled by their customer or an authorized sales user.');
        }
    }

    private function notifyCustomer(SalesOrder $so, string $action, string $message): void
    {
        app(NotificationService::class)->sendPortalInApp(CustomerPortalUser::where('customer_id', $so->customer_id)->where('is_active', true)->get(), 'sales_order.'.$action, [
            'title' => $so->so_number.': '.str_replace('_', ' ', $action), 'message' => $message,
            'link_to' => '/portal/customer/orders/'.$so->hash_id, 'entity_type' => 'sales_order', 'entity_id' => $so->hash_id,
        ], 'so:'.$so->id.':'.$action.':'.($action === 'review_requested' ? $so->customer_confirmation_requested_at->toISOString() : 'once'));
    }

    public function delete(SalesOrder $so): void
    {
        DB::transaction(function () use ($so): void {
            $lockedSo = SalesOrder::query()
                ->lockForUpdate()
                ->findOrFail($so->id);
            if ($lockedSo->status !== SalesOrderStatus::Draft) {
                throw new BusinessRuleException('Only draft sales orders can be deleted.');
            }
            $this->assertCustomerOrderMutation($lockedSo, null);
            $lockedSo->delete();
        });
    }

    public function restore(SalesOrder $so): SalesOrder
    {
        return DB::transaction(function () use ($so): SalesOrder {
            $lockedSo = SalesOrder::withTrashed()
                ->lockForUpdate()
                ->findOrFail($so->id);
            if ($lockedSo->trashed()) {
                $lockedSo->restore();
            }

            return $lockedSo->fresh();
        });
    }

    // ─── C-2: Lifecycle transitions wired from WO / Delivery / Invoice ──────
    //
    // These helpers are called from listeners and sibling services that do
    // NOT own SO state. They are idempotent and lock-aware, but an invalid
    // transition is an error: the owning operation must roll back rather than
    // succeeding while the SO silently remains stale.

    public function markInProduction(?int $salesOrderId): SalesOrderTransitionResult
    {
        return $this->transitionOrFail($salesOrderId, SalesOrderStatus::InProduction);
    }

    public function markPartiallyDelivered(?int $salesOrderId): SalesOrderTransitionResult
    {
        return $this->transitionOrFail($salesOrderId, SalesOrderStatus::PartiallyDelivered);
    }

    public function markDelivered(?int $salesOrderId): SalesOrderTransitionResult
    {
        return $this->transitionOrFail($salesOrderId, SalesOrderStatus::Delivered);
    }

    public function markInvoiced(?int $salesOrderId): SalesOrderTransitionResult
    {
        return $this->transitionOrFail($salesOrderId, SalesOrderStatus::Invoiced);
    }

    public function markPaid(?int $salesOrderId): SalesOrderTransitionResult
    {
        return $this->transitionOrFail($salesOrderId, SalesOrderStatus::Paid);
    }

    public function markClosed(?int $salesOrderId): SalesOrderTransitionResult
    {
        return $this->transitionOrFail($salesOrderId, SalesOrderStatus::Closed);
    }

    /**
     * Reconcile the SO's coarse financial terminal state from its linked
     * invoices and delivered quantities. The owning collection or delivery
     * transaction calls this while its own write is still open.
     */
    public function synchronizeCompletionState(?int $salesOrderId): void
    {
        if ($salesOrderId === null) {
            return;
        }

        DB::transaction(function () use ($salesOrderId): void {
            $so = SalesOrder::query()->lockForUpdate()->find($salesOrderId);
            if (! $so || in_array($so->status, [SalesOrderStatus::Cancelled, SalesOrderStatus::Closed], true)) {
                return;
            }

            $invoices = Invoice::query()
                ->where('sales_order_id', $so->id)
                ->where('status', '!=', InvoiceStatus::Cancelled->value)
                ->get(['id', 'status', 'date', 'created_at', 'total_amount']);
            if ($invoices->isEmpty() || ! $invoices->every(
                static fn (Invoice $invoice): bool => $invoice->status === InvoiceStatus::Paid,
            )) {
                return;
            }

            $invoicedTotal = Money::add(...$invoices->pluck('total_amount')->map(
                static fn (mixed $amount): string => (string) $amount,
            )->all());
            $target = $this->isFullyDelivered($so)
                && Money::gte($invoicedTotal, (string) $so->total_amount)
                ? SalesOrderStatus::Closed
                : SalesOrderStatus::Paid;
            // An order that is fully delivered, invoiced and paid closes in one
            // step, which used to skip the Invoiced and Paid transitions
            // entirely — leaving both chain steps done but dateless. Read the
            // real dates off the invoices and their collections instead.
            $stamps = [];
            if (! $so->invoiced_at && $this->invoicesSayInvoiced($invoices)) {
                $stamps['invoiced_at'] = $this->invoicedAt($invoices);
            }
            if (! $so->paid_at) {
                $stamps['paid_at'] = $this->paidAt($invoices);
            }

            $result = $this->transitionLocked($so, $target, $stamps);
            if (! $result->isSuccess()) {
                throw new BusinessRuleException($result->reason ?? 'Sales order completion state is invalid.');
            }
        });
    }

    /** Re-open the physical SO state after its last linked invoice is cancelled. */
    public function synchronizeAfterInvoiceCancellation(?int $salesOrderId): void
    {
        if ($salesOrderId === null) {
            return;
        }

        DB::transaction(function () use ($salesOrderId): void {
            $so = SalesOrder::query()->lockForUpdate()->find($salesOrderId);
            if (! $so || in_array($so->status, [SalesOrderStatus::Cancelled, SalesOrderStatus::Closed], true)) {
                return;
            }

            if (Invoice::query()
                ->where('sales_order_id', $so->id)
                ->where('status', '!=', InvoiceStatus::Cancelled->value)
                ->exists()) {
                return;
            }

            $hasConfirmedDelivery = Delivery::query()
                ->where('sales_order_id', $so->id)
                ->whereIn('status', ['delivered', 'confirmed'])
                ->exists();
            $target = ! $hasConfirmedDelivery
                ? SalesOrderStatus::Confirmed
                : ($this->isFullyDelivered($so)
                    ? SalesOrderStatus::Delivered
                    : SalesOrderStatus::PartiallyDelivered);

            if ($so->status === $target) {
                return;
            }

            $result = $this->transitionLocked($so, $target);
            if (! $result->isSuccess()) {
                throw new BusinessRuleException($result->reason ?? 'Sales order state could not be reconciled after invoice cancellation.');
            }
        });
    }

    private function isFullyDelivered(SalesOrder $so): bool
    {
        $items = SalesOrderItem::query()
            ->where('sales_order_id', $so->id)
            ->get(['quantity', 'quantity_delivered']);

        return $items->isNotEmpty() && $items->every(
            static fn (SalesOrderItem $item): bool => Money::gte(
                (string) ($item->quantity_delivered ?? '0.00'),
                (string) $item->quantity,
            ),
        );
    }

    private function transitionOrFail(?int $salesOrderId, SalesOrderStatus $target): SalesOrderTransitionResult
    {
        $result = $this->transitionTo($salesOrderId, $target);
        if (! $result->isSuccess() && $salesOrderId !== null) {
            throw new BusinessRuleException(
                $result->reason ?? "Sales order could not transition to {$target->value}.",
            );
        }

        return $result;
    }

    private function transitionTo(?int $salesOrderId, SalesOrderStatus $target): SalesOrderTransitionResult
    {
        if ($salesOrderId === null) {
            return new SalesOrderTransitionResult('skipped', 422, null, $target->value, 'sales_order_missing');
        }

        return DB::transaction(function () use ($salesOrderId, $target): SalesOrderTransitionResult {
            $so = SalesOrder::lockForUpdate()->find($salesOrderId);
            if (! $so) {
                return new SalesOrderTransitionResult('skipped', 422, null, $target->value, 'sales_order_missing');
            }

            return $this->transitionLocked($so, $target);
        });
    }

    /**
     * True once every invoice on the order is past draft, so `invoiced_at` can
     * be dated even when the order never sat in the Invoiced status.
     */
    private function invoicesSayInvoiced(Collection $invoices): bool
    {
        return $invoices->isNotEmpty() && $invoices->every(
            static fn (Invoice $invoice): bool => $invoice->status !== InvoiceStatus::Draft,
        );
    }

    /** When the order was billed, taken from the invoices rather than the clock. */
    private function invoicedAt(Collection $invoices): ?Carbon
    {
        $latest = $invoices->sortByDesc(
            static fn (Invoice $invoice): string => (string) ($invoice->date?->toDateString() ?? $invoice->created_at),
        )->first();

        return $latest?->date?->copy()->startOfDay();
    }

    /** The day the last collection settled the order, so `paid_at` is evidence, not `now()`. */
    private function paidAt(Collection $invoices): ?Carbon
    {
        $last = InvoiceCollection::query()
            ->whereIn('invoice_id', $invoices->pluck('id')->all())
            ->max('collection_date');

        return $last === null ? null : Carbon::parse((string) $last);
    }

    private function transitionLocked(SalesOrder $so, SalesOrderStatus $target, array $extra = []): SalesOrderTransitionResult
    {
        $currentValue = $so->status?->value;

        if ($currentValue === $target->value) {
            return new SalesOrderTransitionResult('succeeded', 200, $currentValue, $target->value);
        }

        $allowed = self::ALLOWED_TRANSITIONS[$currentValue ?? ''] ?? [];
        if (! in_array($target->value, $allowed, true)) {
            $reason = "Transition from {$currentValue} to {$target->value} is not allowed.";
            SalesOrderTransitionRejection::create([
                'sales_order_id' => $so->id, 'from_status' => $currentValue,
                'to_status' => $target->value, 'reason_code' => 'illegal_transition',
                'reason' => $reason, 'requested_by' => null,
            ]);
            Log::debug('SalesOrder transition skipped', [
                'sales_order_id' => $so->id,
                'from' => $currentValue,
                'to' => $target->value,
            ]);

            return new SalesOrderTransitionResult('skipped', 409, $currentValue, $target->value, $reason);
        }

        $so->update([
            'status' => $target->value,
            ...$this->transitionTimestamp($target),
            ...$extra,
        ]);
        $fresh = $so->fresh();
        app(ChainBroadcaster::class)->broadcastFor($fresh, $target->value);

        return new SalesOrderTransitionResult('succeeded', 200, $currentValue, $target->value);
    }

    /**
     * Find the most recent MRP run that evaluated this SO and failed it, so
     * the confirmation response can report a failure instead of a lie about
     * work still being queued.
     *
     * @return array{message: string, recovery_action: ?string}|null
     */
    private function latestPlanningFailure(int $salesOrderId): ?array
    {
        $runs = MrpRun::query()
            ->where(function ($query) use ($salesOrderId): void {
                $query->whereJsonContains('summary->per_sales_order', [['so_id' => $salesOrderId]])
                    ->orWhereJsonContains('summary->sales_order_ids', $salesOrderId);
            })
            ->orderByDesc('id')
            ->limit(1)
            ->get(['id', 'status', 'summary', 'error_message', 'recovery_action']);

        foreach ($runs as $run) {
            foreach ((array) ($run->summary['per_sales_order'] ?? []) as $row) {
                if (! is_array($row) || (int) ($row['so_id'] ?? 0) !== $salesOrderId) {
                    continue;
                }

                if (! isset($row['error'])) {
                    $warning = collect((array) ($row['warnings'] ?? []))->firstWhere('type', 'missing_bom');
                    if ($warning) {
                        return ['message' => (string) $warning['message'], 'recovery_action' => 'Complete the product BOM, then run MRP again.'];
                    }

                    return null; // The newest run that saw this SO planned it cleanly.
                }

                return [
                    'message' => isset($row['error_code']) ? (string) $row['error'] : 'MRP could not evaluate this sales order. Ask the administrator to review the run logs, then rerun MRP.',
                    'recovery_action' => isset($row['recovery_action']) ? (string) $row['recovery_action'] : null,
                ];
            }

            if ($run->status === MrpRunStatus::Failed) {
                return [
                    'message' => $run->error_code ? (string) ($run->error_message ?: 'Automatic MRP run failed without an error message.') : 'Automatic MRP failed. Ask the administrator to review the run logs, then rerun MRP.',
                    'recovery_action' => $run->recovery_action !== null ? (string) $run->recovery_action : null,
                ];
            }
        }

        return null;
    }

    /**
     * Chain progress reflects completed quantities and downstream records.
     */
    public function chain(SalesOrder $so): array
    {
        $status = $so->status;
        $isCancelled = $status === SalesOrderStatus::Cancelled;
        $isPaid = in_array($status, [SalesOrderStatus::Paid, SalesOrderStatus::Closed], true);
        $isClosed = $status === SalesOrderStatus::Closed;
        $qc = $isCancelled
            ? ['state' => 'skipped', 'date' => null]
            : $this->deriveOutgoingQcStage($so);
        $productionCovered = $isCancelled ? false : $this->hasCompletedProduction($so);
        $stockLines = $so->items()->where('fulfillment_policy', 'stock_first')->get();
        $supplyLabel = 'In Production';
        if ($stockLines->isNotEmpty()) {
            $stockOnly = ! WorkOrder::where('sales_order_id', $so->id)->where('status', '!=', 'cancelled')->exists();
            $supplyLabel = $stockOnly ? 'Ready from stock' : 'Stock + production';
            if (! $isCancelled && $productionCovered === true) $qc = ['state' => 'done', 'date' => null];
        }
        if ($qc['state'] === 'done' && $status === SalesOrderStatus::InProduction && $productionCovered === false) {
            $qc['state'] = 'active';
        }
        if ($qc['state'] === 'failed') {
            $qc['state'] = 'rejected';
        }

        // Delivery coverage, not status, decides the Delivered tile. An SO
        // invoiced after a partial shipment still reads `invoiced`; keying the
        // tile off status showed Delivered as done with the partial-delivery
        // date, hiding the goods still owed.
        $fullyDelivered = $this->isFullyDelivered($so);
        $hasDeliveredQuantity = ! $fullyDelivered && SalesOrderItem::query()
            ->where('sales_order_id', $so->id)
            ->where('quantity_delivered', '>', 0)
            ->exists();

        $planningFailure = ! $isCancelled && ! $status->isBeforeConfirmation() && $productionCovered !== true
            ? $this->latestPlanningFailure((int) $so->id) : null;
        $mrpState = match (true) {
            $isCancelled => 'skipped',
            $status->isBeforeConfirmation() => 'pending',
            $productionCovered === true => 'done',
            $planningFailure !== null => 'rejected',
            (bool) $so->mrp_plan_id => 'done',
            $status === SalesOrderStatus::Confirmed => 'active',
            default => 'done',
        };
        $productionState = match (true) {
            $isCancelled => 'skipped',
            $status->isBeforeConfirmation() => 'pending',
            $productionCovered => 'done',
            $status === SalesOrderStatus::Confirmed => 'pending',
            $status === SalesOrderStatus::InProduction => 'active',
            default => 'done',
        };
        $deliveryState = match (true) {
            $isCancelled => 'skipped',
            $fullyDelivered => 'done',
            $hasDeliveredQuantity || ($productionState === 'done' && $qc['state'] === 'done') => 'active',
            default => 'pending',
        };

        // Billing is a fact about the invoices, not about the status: an order
        // can sit in `delivered` with its invoice already finalized, and the
        // tile used to read `pending` while the order had in fact been billed.
        $billing = Invoice::query()
            ->where('sales_order_id', $so->id)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->get(['date', 'status']);
        $invoiced = $billing->isNotEmpty() && ! $billing->contains(
            static fn (Invoice $invoice): bool => $invoice->status === InvoiceStatus::Draft,
        );
        $invoicedDate = $so->invoiced_at?->toDateString()
            ?? ($invoiced ? $billing->max(static fn (Invoice $invoice): ?string => $invoice->date?->toDateString()) : null);

        $mrpDescription = $planningFailure
            ? 'MRP planning needs attention. '.$planningFailure['message'].' PPC: '.($planningFailure['recovery_action'] ?? 'Correct the planning data, then run MRP again from MRP Plans. Do not create another sales order.')
            : ($mrpState === 'active' ? 'Automatic MRP planning is pending. PPC: refresh this record to check for the material plan and work orders. If nothing appears, check MRP Plans for failed runs and ask the administrator to check automation.' : null);
        $missingQualitySpecs = $mrpState === 'done' && in_array($productionState, ['pending', 'active'], true)
            ? Product::query()->whereIn('id', WorkOrder::query()->where('sales_order_id', $so->id)
                ->whereIn('status', ['planned', 'confirmed', 'in_progress', 'paused'])->select('product_id'))
                ->whereDoesntHave('inspectionSpec', fn ($query) => $query->whereHas('items'))
                ->pluck('part_number')->all()
            : [];

        return [
            ['key' => 'order_entered', 'label' => 'Order Entered',
                'date' => $so->created_at?->toDateString(),
                'state' => 'done'],
            ['key' => 'mrp_planned', 'label' => 'MRP Planned',
                'date' => $so->confirmed_at?->toDateString(),
                'state' => $mrpState, 'description' => $mrpDescription, 'href' => '/mrp/plans'],
            ['key' => 'in_production', 'label' => $supplyLabel,
                'date' => $so->in_production_at?->toDateString(),
                'state' => $productionState, 'href' => '/production/work-orders',
                'description' => $missingQualitySpecs !== []
                    ? 'Production setup needs attention. QC: configure the actual inspection specification for '.implode(', ', $missingQualitySpecs).'. Production cannot start those work orders until QC setup is complete.'
                    : ($mrpState === 'done' && in_array($productionState, ['pending', 'active'], true)
                    ? 'Next: PPC reviews material availability and scheduling in the linked MRP plan; Production confirms and starts the linked work orders. Shortages must be purchased and received before material issue.' : null)],
            ['key' => 'qc_outgoing', 'label' => $supplyLabel === 'Ready from stock' ? 'QC approved stock' : 'QC Outgoing', 'date' => $qc['date'], 'state' => $qc['state'],
                'href' => '/quality/inspections', 'description' => $qc['state'] === 'rejected'
                    ? 'Quality hold: QC must record the defect disposition and approve recovery inspection. Production handles required rework; affected goods cannot ship.'
                    : ($qc['state'] === 'active' ? 'Next: QC records PASS/FAIL for each checklist requirement, then an independent checker reviews the result. Production cannot close the work order until quality is released.' : null)],
            ['key' => 'delivered', 'label' => 'Delivered',
                'date' => ($so->delivered_at ?? $so->partially_delivered_at)?->toDateString(),
                'state' => $deliveryState, 'description' => ! $isCancelled && $qc['state'] === 'done' && ! $fullyDelivered
                    ? 'Next: ImpEx prepares the linked deliveries and reserves released stock. Driver uploads proof; customer confirms receipt. Partial shipments keep the remaining quantity open.' : null],
            ['key' => 'invoiced', 'label' => 'Invoiced',
                'date' => $invoicedDate,
                'state' => $isCancelled ? 'skipped' : ($invoiced || $isPaid || $isClosed || $status === SalesOrderStatus::Invoiced ? 'done' : 'pending')],
            ['key' => 'paid', 'label' => 'Paid',
                'date' => $so->paid_at?->toDateString(),
                'state' => $isCancelled ? 'skipped' : ($isPaid ? 'done' : 'pending')],
            ['key' => 'closed', 'label' => 'Closed',
                'date' => $so->closed_at?->toDateString(),
                'state' => $isCancelled ? 'skipped' : ($isClosed ? 'done' : 'pending')],
        ];
    }

    private function hasCompletedProduction(SalesOrder $so): ?bool
    {
        if ($so->items()->where('fulfillment_policy', 'stock_first')->exists()) {
            return $so->items()->get()->every(function ($line) {
                $coverage = app(SalesOrderSupplyService::class)->lineCoverage($line);
                return bccomp($coverage['ready_quantity'], $coverage['demand'], 3) >= 0;
            });
        }
        $demand = SalesOrderItem::query()->where('sales_order_id', $so->id)->get()
            ->groupBy('product_id')->map(fn ($lines) => $lines->sum('quantity'));
        if ($demand->isEmpty()) {
            return null;
        }
        $completed = WorkOrder::query()->where('sales_order_id', $so->id)->whereNull('parent_wo_id')
            ->whereIn('status', [WorkOrderStatus::Completed->value, WorkOrderStatus::Closed->value])
            ->get(['product_id', 'quantity_good'])->groupBy('product_id')
            ->map(fn ($orders) => $orders->sum('quantity_good'));

        // Surplus for one product cannot cover another product's demand.
        return $demand->every(fn ($quantity, $productId) => $completed->get($productId, 0) >= $quantity);
    }

    /**
     * A failed or unfinished batch takes priority over newer passing batches.
     * Closed scrap/rework dispositions remain resolved without rewriting QC history.
     *
     * @return array{state: string, date: ?string}
     */
    private function deriveOutgoingQcStage(SalesOrder $so): array
    {
        $inspections = Inspection::query()
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->whereIn('entity_id', WorkOrder::query()->where('sales_order_id', $so->id)->where('status', '!=', WorkOrderStatus::Cancelled->value)->select('id'))
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('status', '!=', 'cancelled')->with(['ncr', 'resultAuthors', 'workOrderOutput.workOrder'])->orderByDesc('id')->get();
        $unresolved = $inspections->filter(static fn ($inspection): bool => ! $inspection->hasResolvedOutgoingDisposition());
        $row = $unresolved->firstWhere('status', InspectionStatus::Failed)
            ?? $unresolved->first(static fn ($inspection): bool => ! in_array($inspection->status->value, ['passed', 'failed'], true))
            ?? $inspections->first();

        if ($row === null) {
            return ['state' => 'pending', 'date' => null];
        }

        $status = $row->hasResolvedOutgoingDisposition() ? 'passed' : $row->status->value;
        if ($status === 'passed') {
            $batched = $inspections->whereNotNull('work_order_output_id');
            if ($batched->isNotEmpty()) {
                $demand = SalesOrderItem::query()->where('sales_order_id', $so->id)->get()
                    ->groupBy('product_id')->map(fn ($lines) => $lines->sum('quantity'));
                $released = $batched->filter(fn (Inspection $inspection) => $inspection->canReleaseOutgoing())
                    ->groupBy('product_id')->map(fn ($rows) => $rows->sum('accepted_quantity'));
                if ($demand->isNotEmpty() && ! $demand->every(fn ($quantity, $productId) => $released->get($productId, 0) >= $quantity)) {
                    return ['state' => 'active', 'date' => null];
                }
            }
            return ['state' => 'done', 'date' => optional(Carbon::parse($row->completed_at))->toDateString()];
        }
        if ($status === 'failed') {
            return ['state' => 'failed', 'date' => optional(Carbon::parse($row->completed_at))->toDateString()];
        }

        return ['state' => 'active', 'date' => optional(Carbon::parse($row->started_at))->toDateString()];
    }
}
