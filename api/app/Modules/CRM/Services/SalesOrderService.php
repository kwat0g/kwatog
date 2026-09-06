<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderTransitionRejection;
use App\Modules\CRM\Support\SalesOrderTransitionResult;
use App\Modules\Production\Models\WorkOrder;
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
     * Backwards and terminal transitions remain absent, and an illegal
     * transition is a hard error (see transitionOrFail) so the owning
     * operation rolls back rather than succeeding while the SO goes stale.
     *
     * `cancelled` is not a target here on purpose: no mark* helper requests
     * it. Cancellation goes through cancel(), which has its own downstream
     * reconciliation guards (assertCancellableDownstreamState).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'confirmed'           => ['in_production', 'partially_delivered', 'delivered', 'invoiced'],
        'in_production'       => ['partially_delivered', 'delivered', 'invoiced'],
        'partially_delivered' => ['delivered', 'invoiced'],
        'delivered'           => ['invoiced'],
        'invoiced'            => [],
        'cancelled'           => [],
        'draft'               => [],
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

        $arBalance = (string) (\App\Modules\Accounting\Models\Invoice::query()
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
                app(\App\Common\Services\CurrencyDisplayService::class)->format($limit),
                app(\App\Common\Services\CurrencyDisplayService::class)->format($totalExposure),
                app(\App\Common\Services\CurrencyDisplayService::class)->format($arBalance),
                app(\App\Common\Services\CurrencyDisplayService::class)->format($openSoExposure),
                app(\App\Common\Services\CurrencyDisplayService::class)->format($so->total_amount),
            );
            throw ValidationException::withMessages([
                'credit_limit' => [$msg],
            ]);
        }
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
            SalesOrderStatus::Cancelled => ['cancelled_at' => now()],
            default => [],
        };
    }

    public function list(array $filters): LengthAwarePaginator
    {
        $q = SalesOrder::query()
            ->with(['customer:id,name', 'creator:id,name,role_id'])
            ->withCount('items');

        TrashedFilter::apply($q, $filters);

        if (! empty($filters['customer_id'])) {
            $cid = HashIdFilter::decode($filters['customer_id'], Customer::class);
            if ($cid) $q->where('customer_id', $cid);
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
                $qq->where('so_number', SearchOperator::like(), "%{$term}%")
                   ->orWhereHas('customer', fn ($c) => $c->where('name', SearchOperator::like(), "%{$term}%"));
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
            $orderDate  = Carbon::parse($data['date']);
            $items      = $data['items'];

            $this->lockOrderReferences($customerId, $items);
            $this->assertActiveOrderReferences($customerId, $items);
            $this->assertDeliveryDatesNotBeforeOrder($orderDate, $items);

            // Resolve every line's unit_price from the active agreement. Keep
            // quantities and monetary values as decimal strings throughout so
            // PHP floating-point arithmetic cannot change persisted totals.
            $lines = [];
            $subtotal = Money::zero();
            foreach ($items as $idx => $item) {
                $productId    = (int) $item['product_id'];
                $deliveryDate = Carbon::parse($item['delivery_date']);
                $qty          = (string) $item['quantity'];

                // Resolve at delivery_date — that's the date the price applies to.
                try {
                    $agreement = $this->prices->resolve($customerId, $productId, $deliveryDate);
                } catch (\App\Modules\CRM\Exceptions\NoPriceAgreementException $e) {
                    throw new \App\Modules\CRM\Exceptions\NoPriceAgreementException("items.{$idx}.product_id");
                }
                // Volume tier resolution: tiered agreements pick the unit price
                // for this line's quantity; flat agreements return $agreement->price.
                $unitPrice = (string) $this->prices->resolveUnitPrice($agreement, $qty);
                $lineTotal = Money::mul($qty, $unitPrice);

                $lines[] = [
                    'product_id'         => $productId,
                    'quantity'           => $qty,
                    'unit_price'         => $unitPrice,
                    'total'              => $lineTotal,
                    'quantity_delivered' => 0,
                    'delivery_date'      => $deliveryDate->toDateString(),
                ];
                $subtotal = Money::add($subtotal, $lineTotal);
            }

            $isVatable = $this->taxPolicy->isVatRegistered();
            $vat   = $isVatable ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate()) : Money::zero();
            $total = Money::add($subtotal, $vat);

            $so = SalesOrder::create([
                'so_number'          => $this->sequences->generate('sales_order'),
                'customer_id'        => $customerId,
                'date'               => $orderDate->toDateString(),
                'subtotal'           => $subtotal,
                'vat_amount'         => $vat,
                'total_amount'       => $total,
                'status'             => SalesOrderStatus::Draft->value,
                'payment_terms_days' => $data['payment_terms_days']
                    ?? (int) Customer::query()->whereKey($customerId)->value('payment_terms_days'),
                'delivery_terms'     => $data['delivery_terms'] ?? null,
                'notes'              => $data['notes'] ?? null,
                'incoterm'           => $data['incoterm'] ?? null,
                'created_by'         => $userId,
            ]);

            // Persist lines.
            foreach ($lines as $line) {
                $so->items()->create($line);
            }

            return $this->show($so->fresh());
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
            if ($lockedSo->status !== SalesOrderStatus::Draft) {
                throw new BusinessRuleException('Only draft sales orders can be updated.');
            }

            $customerId = (int) ($data['customer_id'] ?? $lockedSo->customer_id);
            $orderDate  = Carbon::parse($data['date'] ?? $lockedSo->date->toDateString());
            $items      = $data['items'];

            $this->lockOrderReferences($customerId, $items);
            $this->assertActiveOrderReferences($customerId, $items);
            $this->assertDeliveryDatesNotBeforeOrder($orderDate, $items);

            $subtotal = Money::zero();
            $newLines = [];

            foreach ($items as $idx => $item) {
                $productId    = (int) $item['product_id'];
                $deliveryDate = Carbon::parse($item['delivery_date']);
                $qty          = (string) $item['quantity'];
                try {
                    $agreement = $this->prices->resolve($customerId, $productId, $deliveryDate);
                } catch (\App\Modules\CRM\Exceptions\NoPriceAgreementException $e) {
                    throw new \App\Modules\CRM\Exceptions\NoPriceAgreementException("items.{$idx}.product_id");
                }
                // Volume tier resolution (see create()).
                $unitPrice = (string) $this->prices->resolveUnitPrice($agreement, $qty);
                $lineTotal = Money::mul($qty, $unitPrice);
                $newLines[] = [
                    'product_id'         => $productId,
                    'quantity'           => $qty,
                    'unit_price'         => $unitPrice,
                    'total'              => $lineTotal,
                    'quantity_delivered' => 0,
                    'delivery_date'      => $deliveryDate->toDateString(),
                ];
                $subtotal = Money::add($subtotal, $lineTotal);
            }
            $isVatable = $this->taxPolicy->isVatRegistered();
            $vat   = $isVatable ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate()) : Money::zero();
            $total = Money::add($subtotal, $vat);

            $lockedSo->update([
                'customer_id'        => $customerId,
                'date'               => $orderDate->toDateString(),
                'subtotal'           => $subtotal,
                'vat_amount'         => $vat,
                'total_amount'       => $total,
                'payment_terms_days' => $data['payment_terms_days'] ?? $lockedSo->payment_terms_days,
                'delivery_terms'     => array_key_exists('delivery_terms', $data)
                    ? $data['delivery_terms']
                    : $lockedSo->delivery_terms,
                'incoterm'           => array_key_exists('incoterm', $data)
                    ? $data['incoterm']
                    : $lockedSo->incoterm?->value,
                'notes'              => array_key_exists('notes', $data)
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
            app(\App\Common\Services\ChainBroadcaster::class)->broadcastFor(
                $fresh,
                SalesOrderStatus::Confirmed->value,
                auth()->user(),
            );

            return $this->show($fresh);
        });
    }

    /**
     * Resolve the CapacityPlanningService via the container so this module
     * can run without the MRP module being booted.
     */
    private function capacityPlanner(): ?\App\Modules\MRP\Services\CapacityPlanningService
    {
        $cls = '\\App\\Modules\\MRP\\Services\\CapacityPlanningService';
        return class_exists($cls) ? app($cls) : null;
    }

    /**
     * Resolve PickingListService via the container (optional dependency).
     */
    private function pickingListService(): ?\App\Modules\Inventory\Services\PickingListService
    {
        $cls = '\\App\\Modules\\Inventory\\Services\\PickingListService';
        return class_exists($cls) ? app($cls) : null;
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
            'mrpPlan',
            'workOrders.product:id,part_number,name',
            'workOrders.machine:id,machine_code,name',
            'workOrders.mold:id,mold_code,name',
        ]);

        $plan = $confirmedSo->mrpPlan;
        $workOrders = $confirmedSo->workOrders;

        $planSummary = (array) ($plan?->summary ?? []);
        $schedulingResult = $planSummary['scheduling'] ?? ['scheduled' => [], 'conflicts' => []];
        $planningStatus = $plan ? 'completed' : 'queued';

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
                'machine' => $wo->machine ? $wo->machine->machine_code . ' ' . $wo->machine->name : null,
                'scheduled_start' => $schedule['scheduled_start'] ?? optional($wo->planned_start)->toIso8601String(),
                'scheduled_end' => $schedule['scheduled_end'] ?? optional($wo->planned_end)->toIso8601String(),
                'needs_manual_scheduling' => $wo->status?->value === 'planned' && !$wo->machine_id,
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
                'notes'  => trim(($lockedSo->notes ?? '') . "\n\n[Cancelled" . ($reason ? ': ' . $reason : '') . ']'),
            ]);

            // Sprint 6 audit §1.2: cascade through the chain.
            //  1. Supersede the active MRP plan (status='cancelled').
            //  2. Cancel any planned/confirmed/paused WO linked to this SO via
            //     the MRP plan; in_progress/completed/closed WOs are left
            //     alone — the operator must finish or cancel them manually.
            //     WorkOrderService::cancel() releases each WO's reservations.
            $plan = \App\Modules\MRP\Models\MrpPlan::where('sales_order_id', $lockedSo->id)
                ->where('status', \App\Modules\MRP\Enums\MrpPlanStatus::Active->value)
                ->lockForUpdate()
                ->first();
            if ($plan) {
                $plan->update(['status' => \App\Modules\MRP\Enums\MrpPlanStatus::Cancelled->value]);
            }

            $woService = $this->workOrderService();
            if ($woService) {
                $cancellableStatuses = [
                    \App\Modules\Production\Enums\WorkOrderStatus::Planned->value,
                    \App\Modules\Production\Enums\WorkOrderStatus::Confirmed->value,
                    \App\Modules\Production\Enums\WorkOrderStatus::Paused->value,
                ];
                $linkedWos = \App\Modules\Production\Models\WorkOrder::query()
                    ->where('sales_order_id', $lockedSo->id)
                    ->whereIn('status', $cancellableStatuses)
                    ->lockForUpdate()
                    ->get();
                foreach ($linkedWos as $wo) {
                    $woService->cancel($wo, $reason ?? "Sales order {$lockedSo->so_number} cancelled");
                }
            }

            // Series C — Task C4. Stage real-time chain progress atomically
            // with the cancellation and its downstream work-order changes.
            $fresh = $lockedSo->fresh();
            app(\App\Common\Services\ChainBroadcaster::class)->broadcastFor(
                $fresh,
                \App\Modules\CRM\Enums\SalesOrderStatus::Cancelled->value,
                auth()->user(),
            );

            return $this->show($fresh);
        });
    }

    /**
     * Resolve the production WorkOrderService through the container so that
     * the CRM module's tests can run without booting the Production module.
     */
    private function workOrderService(): ?\App\Modules\Production\Services\WorkOrderService
    {
        $cls = '\\App\\Modules\\Production\\Services\\WorkOrderService';
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

            $currentValue = $so->status?->value;

            // Idempotent: already at the target state.
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
                    'from'           => $currentValue,
                    'to'             => $target->value,
                ]);
                return new SalesOrderTransitionResult('skipped', 409, $currentValue, $target->value, $reason);
            }

            $so->update([
                'status' => $target->value,
                ...$this->transitionTimestamp($target),
            ]);
            $fresh = $so->fresh();
            app(\App\Common\Services\ChainBroadcaster::class)->broadcastFor($fresh, $target->value);
            return new SalesOrderTransitionResult('succeeded', 200, $currentValue, $target->value);
        });
    }

    /**
     * Chain payload — qc_outgoing derived from real Inspection state (H-4);
     * other 5 stages still derive from the SO's own status field.
     */
    public function chain(SalesOrder $so): array
    {
        $status = $so->status;
        $isCancelled = $status === SalesOrderStatus::Cancelled;
        $qc = $isCancelled
            ? ['state' => 'skipped', 'date' => null]
            : $this->deriveOutgoingQcStage($so);
        if ($qc['state'] === 'failed') {
            $qc['state'] = 'rejected';
        }

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
            $status === SalesOrderStatus::Draft, $status === SalesOrderStatus::Confirmed,
            $status === SalesOrderStatus::InProduction => 'pending',
            $status === SalesOrderStatus::PartiallyDelivered => 'active',
            default => 'done',
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
             'state' => $isCancelled ? 'skipped' : ($status === SalesOrderStatus::Invoiced ? 'done' : 'pending')],
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
