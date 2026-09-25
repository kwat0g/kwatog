<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Exceptions\NoPriceAgreementException;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderTransitionRejection;
use App\Modules\ReturnManagement\Models\ReturnCase;
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
use App\Modules\SupplyChain\Models\Delivery;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * Cancellation is intentionally narrower than a status transition. Once
     * deliveries, invoices, or active production work exist, their own
     * reconciliation flows must decide how those records are closed.
     */
    private function assertCancellableDownstreamState(SalesOrder $so): void
    {
        if (Delivery::query()
            ->where('sales_order_id', $so->id)
            ->where('status', '!=', 'cancelled')
            ->exists()) {
            throw new BusinessRuleException('This sales order cannot be cancelled while a delivery is active.');
        }

        if (Invoice::query()
            ->where('sales_order_id', $so->id)
            ->where('status', '!=', 'cancelled')
            ->exists()) {
            throw new BusinessRuleException('This sales order cannot be cancelled while an invoice is active.');
        }

        if (WorkOrder::query()
            ->where('sales_order_id', $so->id)
            ->whereIn('status', ['in_progress', 'completed', 'closed'])
            ->exists()) {
            throw new BusinessRuleException('This sales order cannot be cancelled while production work is in progress or complete.');
        }
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
            ->withCount('items');

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

        return $q->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
    }

    public function show(SalesOrder $so): SalesOrder
    {
        // Sprint 6 audit §3.2: eager-load MRP plan + linked work orders so
        // the SO detail page can render the right-panel LinkedRecords block
        // without N+1 round-trips. mrpPlan + workOrders relations are
        // defined on the SalesOrder model (Sprint 6 Task 52).
        return $so->load([
            'customer',
            'creator:id,name,role_id',
            'latestResponse.items',
            'items.product:id,part_number,name,unit_of_measure',
            'mrpPlan:id,mrp_plan_no,version,status,shortages_found,auto_pr_count,draft_wo_count,sales_order_id',
            'workOrders:id,wo_number,product_id,status,quantity_target,quantity_produced,sales_order_id,mrp_plan_id,planned_start',
            'workOrders.product:id,part_number,name',
            'workOrders.inspections:id,inspection_number,stage,status,entity_type,entity_id,completed_at',
            'deliveries:id,delivery_number,sales_order_id,status,scheduled_date',
            'invoices:id,invoice_number,sales_order_id,status,total_amount,balance',
        ]);
    }

    /**
     * Create a draft SO. Resolves price-per-line via PriceAgreementService;
     * throws NoPriceAgreementException (422) if any line has no agreement.
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

            // Resolve every line's unit_price from the active agreement. Keep
            // quantities and monetary values as decimal strings throughout so
            // PHP floating-point arithmetic cannot change persisted totals.
            $lines = [];
            $subtotal = Money::zero();
            foreach ($items as $idx => $item) {
                $productId = (int) $item['product_id'];
                $deliveryDate = Carbon::parse($item['delivery_date']);
                $qty = (string) $item['quantity'];

                // Resolve at delivery_date — that's the date the price applies to.
                try {
                    $agreement = $this->prices->resolve($customerId, $productId, $deliveryDate);
                } catch (NoPriceAgreementException $e) {
                    throw new NoPriceAgreementException("items.{$idx}.product_id");
                }
                // Volume tier resolution: tiered agreements pick the unit price
                // for this line's quantity; flat agreements return $agreement->price.
                $unitPrice = (string) $this->prices->resolveUnitPrice($agreement, $qty);
                $lineTotal = Money::mul($qty, $unitPrice);

                $lines[] = [
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'total' => $lineTotal,
                    'quantity_delivered' => 0,
                    'delivery_date' => $deliveryDate->toDateString(),
                ];
                $subtotal = Money::add($subtotal, $lineTotal);
            }

            $isVatable = $this->taxPolicy->isVatRegistered();
            $vat = $isVatable ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate()) : Money::zero();
            $total = Money::add($subtotal, $vat);

            $so = SalesOrder::create([
                'so_number' => $this->sequences->generate('sales_order'),
                'customer_id' => $customerId,
                'date' => $orderDate->toDateString(),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total_amount' => $total,
                'status' => SalesOrderStatus::Draft->value,
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
                $so->items()->create($line);
            }

            return $this->show($so->fresh());
        });
    }

    /** Create the sole approved, zero-price order used for case redelivery. */
    public function createNoChargeReplacement(ReturnCase $case, int $userId): SalesOrder
    {
        return DB::transaction(function () use ($case, $userId): SalesOrder {
            $lockedCase = ReturnCase::query()->lockForUpdate()->findOrFail($case->id);
            $approver = \App\Modules\Auth\Models\User::query()->findOrFail($userId);
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
    public function update(SalesOrder $so, array $data): SalesOrder
    {
        return DB::transaction(function () use ($so, $data) {
            // Never trust the route-bound snapshot for the state guard. A
            // concurrent confirm/delete may have changed it after binding.
            $lockedSo = SalesOrder::query()
                ->lockForUpdate()
                ->findOrFail($so->id);
            if ($lockedSo->return_case_id !== null) {
                throw new BusinessRuleException('Approved no-charge replacement orders cannot be edited commercially. Update the linked return case through its approval workflow.');
            }
            if ($lockedSo->status !== SalesOrderStatus::Draft) {
                throw new BusinessRuleException('Only draft sales orders can be updated.');
            }

            $customerId = (int) ($data['customer_id'] ?? $lockedSo->customer_id);
            $orderDate = Carbon::parse($data['date'] ?? $lockedSo->date->toDateString());
            $items = $data['items'];

            $this->lockOrderReferences($customerId, $items);
            $this->assertActiveOrderReferences($customerId, $items);
            $this->assertDeliveryDatesNotBeforeOrder($orderDate, $items);

            $subtotal = Money::zero();
            $newLines = [];

            foreach ($items as $idx => $item) {
                $productId = (int) $item['product_id'];
                $deliveryDate = Carbon::parse($item['delivery_date']);
                $qty = (string) $item['quantity'];
                try {
                    $agreement = $this->prices->resolve($customerId, $productId, $deliveryDate);
                } catch (NoPriceAgreementException $e) {
                    throw new NoPriceAgreementException("items.{$idx}.product_id");
                }
                // Volume tier resolution (see create()).
                $unitPrice = (string) $this->prices->resolveUnitPrice($agreement, $qty);
                $lineTotal = Money::mul($qty, $unitPrice);
                $newLines[] = [
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'total' => $lineTotal,
                    'quantity_delivered' => 0,
                    'delivery_date' => $deliveryDate->toDateString(),
                ];
                $subtotal = Money::add($subtotal, $lineTotal);
            }
            $isVatable = $this->taxPolicy->isVatRegistered();
            $vat = $isVatable ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate()) : Money::zero();
            $total = Money::add($subtotal, $vat);

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

            // Replace items wholesale (draft state — no FK ramifications yet).
            $lockedSo->items()->forceDelete();
            foreach ($newLines as $line) {
                $lockedSo->items()->create($line);
            }

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
            if ($lockedSo->status !== SalesOrderStatus::Draft) {
                throw new BusinessRuleException('Only draft sales orders can be confirmed.');
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
                new SalesOrderConfirmed($fresh),
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

            $lockedSo->update(['customer_confirmation_requested_at' => now()]);

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
        $planningFailure = $plan === null ? $this->latestPlanningFailure((int) $confirmedSo->id) : null;
        $planningStatus = match (true) {
            $plan !== null => 'completed',
            $planningFailure !== null => 'failed',
            default => 'queued',
        };

        // Reload WOs after scheduling may have changed their machine/mold.
        $confirmedSo->load([
            'workOrders.product:id,part_number,name',
            'workOrders.machine:id,machine_code,name',
            'workOrders.mold:id,mold_code,name',
            'workOrders.schedules',
        ]);
        $workOrders = $confirmedSo->workOrders;

        // Build per-WO summaries.
        $woSummaries = $workOrders->map(function ($wo) use ($schedulingResult) {
            $schedule = collect($schedulingResult['scheduled'])
                ->firstWhere('work_order_id', $wo->hash_id);

            return [
                'id' => $wo->hash_id,
                'wo_number' => $wo->wo_number,
                'product' => $wo->product ? [
                    'part_number' => $wo->product->part_number,
                    'name' => $wo->product->name,
                ] : null,
                'status' => $wo->status?->value,
                'quantity_target' => (int) $wo->quantity_target,
                'machine' => $wo->machine ? $wo->machine->machine_code.' '.$wo->machine->name : null,
                'scheduled_start' => $schedule['scheduled_start'] ?? optional($wo->planned_start)->toIso8601String(),
                'scheduled_end' => $schedule['scheduled_end'] ?? optional($wo->planned_end)->toIso8601String(),
                'needs_manual_scheduling' => $wo->status?->value === 'planned' && ! $wo->machine_id,
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

    public function cancel(SalesOrder $so, ?string $reason = null): SalesOrder
    {
        return DB::transaction(function () use ($so, $reason) {
            // A stale route-bound SO must not overwrite a delivered/invoiced
            // or already-cancelled order. Re-read and lock before the guard.
            $lockedSo = SalesOrder::query()
                ->lockForUpdate()
                ->findOrFail($so->id);
            if (! $lockedSo->is_cancellable) {
                throw new BusinessRuleException('This sales order cannot be cancelled at its current status.');
            }
            $this->assertCancellableDownstreamState($lockedSo);

            $lockedSo->update([
                'status' => SalesOrderStatus::Cancelled->value,
                ...$this->transitionTimestamp(SalesOrderStatus::Cancelled),
                'notes' => trim(($lockedSo->notes ?? '')."\n\n[Cancelled".($reason ? ': '.$reason : '').']'),
            ]);

            // Sprint 6 audit §1.2: cascade through the chain.
            //  1. Supersede the active MRP plan (status='cancelled').
            //  2. Cancel any planned/confirmed/paused WO linked to this SO via
            //     the MRP plan; in_progress/completed/closed WOs are left
            //     alone — the operator must finish or cancel them manually.
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
                    WorkOrderStatus::Paused->value,
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

    public function delete(SalesOrder $so): void
    {
        DB::transaction(function () use ($so): void {
            $lockedSo = SalesOrder::query()
                ->lockForUpdate()
                ->findOrFail($so->id);
            if ($lockedSo->status !== SalesOrderStatus::Draft) {
                throw new BusinessRuleException('Only draft sales orders can be deleted.');
            }
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
                ->get(['status', 'total_amount']);
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
            $result = $this->transitionLocked($so, $target);
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

    private function transitionLocked(SalesOrder $so, SalesOrderStatus $target): SalesOrderTransitionResult
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
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'status', 'summary', 'error_message', 'recovery_action']);

        foreach ($runs as $run) {
            foreach ((array) ($run->summary['per_sales_order'] ?? []) as $row) {
                if (! is_array($row) || (int) ($row['so_id'] ?? 0) !== $salesOrderId) {
                    continue;
                }

                if (! isset($row['error'])) {
                    return null; // The newest run that saw this SO planned it cleanly.
                }

                return [
                    'message' => (string) $row['error'],
                    'recovery_action' => isset($row['recovery_action']) ? (string) $row['recovery_action'] : null,
                ];
            }

            if ($run->status === MrpRunStatus::Failed) {
                return [
                    'message' => (string) ($run->error_message ?: 'Automatic MRP run failed without an error message.'),
                    'recovery_action' => $run->recovery_action !== null ? (string) $run->recovery_action : null,
                ];
            }
        }

        return null;
    }

    /**
     * Chain payload — qc_outgoing derived from real Inspection state (H-4);
     * the remaining stages derive from the SO's own lifecycle state.
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

        $mrpState = match (true) {
            $isCancelled => 'skipped',
            $status === SalesOrderStatus::Draft => 'pending',
            (bool) $so->mrp_plan_id => 'done',
            $status === SalesOrderStatus::Confirmed => 'active',
            default => 'done',
        };
        $productionState = match (true) {
            $isCancelled => 'skipped',
            $status === SalesOrderStatus::Draft, $status === SalesOrderStatus::Confirmed => 'pending',
            $status === SalesOrderStatus::InProduction => 'active',
            default => 'done',
        };
        $deliveryState = match (true) {
            $isCancelled => 'skipped',
            $fullyDelivered => 'done',
            $hasDeliveredQuantity => 'active',
            default => 'pending',
        };

        return [
            ['key' => 'order_entered', 'label' => 'Order Entered',
                'date' => $so->created_at?->toDateString(),
                'state' => 'done'],
            ['key' => 'mrp_planned', 'label' => 'MRP Planned',
                'date' => $so->confirmed_at?->toDateString(),
                'state' => $mrpState],
            ['key' => 'in_production', 'label' => 'In Production',
                'date' => $so->in_production_at?->toDateString(),
                'state' => $productionState],
            ['key' => 'qc_outgoing', 'label' => 'QC Outgoing', 'date' => $qc['date'], 'state' => $qc['state']],
            ['key' => 'delivered', 'label' => 'Delivered',
                'date' => ($so->delivered_at ?? $so->partially_delivered_at)?->toDateString(),
                'state' => $deliveryState],
            ['key' => 'invoiced', 'label' => 'Invoiced',
                'date' => $so->invoiced_at?->toDateString(),
                'state' => $isCancelled ? 'skipped' : ($isPaid || $isClosed || $status === SalesOrderStatus::Invoiced ? 'done' : 'pending')],
            ['key' => 'paid', 'label' => 'Paid',
                'date' => $so->paid_at?->toDateString(),
                'state' => $isCancelled ? 'skipped' : ($isPaid ? 'done' : 'pending')],
            ['key' => 'closed', 'label' => 'Closed',
                'date' => $so->closed_at?->toDateString(),
                'state' => $isCancelled ? 'skipped' : ($isClosed ? 'done' : 'pending')],
        ];
    }

    /**
     * H-4 — Derive the outgoing-QC chain stage from the latest outgoing
     * Inspection joined via WO → SO. Single SQL query, no N+1.
     *
     * States:
     *   pending — no outgoing inspection exists for any WO of this SO
     *   done    — latest outgoing inspection passed; date = completed_at
     *   failed  — latest outgoing inspection failed; date = completed_at
     *   active  — latest exists but not yet terminal (draft / in_progress /
     *             cancelled treated as in-flight); date = started_at
     *
     * @return array{state: string, date: ?string}
     */
    private function deriveOutgoingQcStage(SalesOrder $so): array
    {
        $row = DB::table('inspections as i')
            ->join('work_orders as w', function ($j) {
                $j->on('w.id', '=', 'i.entity_id')
                    ->where('i.entity_type', '=', InspectionEntityType::WorkOrder->value);
            })
            ->where('w.sales_order_id', $so->id)
            ->where('i.stage', InspectionStage::Outgoing->value)
            ->orderByDesc('i.id')
            ->select('i.status', 'i.started_at', 'i.completed_at')
            ->first();

        if ($row === null) {
            return ['state' => 'pending', 'date' => null];
        }

        $status = (string) $row->status;
        if ($status === 'passed') {
            return ['state' => 'done', 'date' => optional(Carbon::parse($row->completed_at))->toDateString()];
        }
        if ($status === 'failed') {
            return ['state' => 'failed', 'date' => optional(Carbon::parse($row->completed_at))->toDateString()];
        }

        return ['state' => 'active', 'date' => optional(Carbon::parse($row->started_at))->toDateString()];
    }
}
