<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SalesOrderService
{
    /** Philippines VAT rate. */
    private const VAT_RATE = 0.12;

    /**
     * C-2 — Allowed SalesOrder status transitions. Each key is a current
     * status; the array is the list of statuses we permit moving INTO.
     * Backwards or terminal transitions are absent so transitionTo() can
     * silently no-op rather than failing upstream operations.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'confirmed'           => ['in_production', 'partially_delivered', 'delivered', 'invoiced', 'cancelled'],
        'in_production'       => ['partially_delivered', 'delivered', 'invoiced', 'cancelled'],
        'partially_delivered' => ['delivered', 'invoiced'],
        'delivered'           => ['invoiced'],
        'invoiced'            => [],
        'cancelled'           => [],
        'draft'               => [],
    ];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PriceAgreementService $prices,
    ) {}

    /**
     * Resolve the MRP engine via the container so this module's tests can run
     * without the MRP module being booted (and so unit tests can mock it).
     * The actual class lives in App\Modules\MRP\Services\MrpEngineService.
     */
    private function mrpEngine(): ?\App\Modules\MRP\Services\MrpEngineService
    {
        $cls = '\\App\\Modules\\MRP\\Services\\MrpEngineService';
        return class_exists($cls) ? app($cls) : null;
    }

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
                'Credit limit exceeded. Limit: ₱%s, Current exposure: ₱%s (AR ₱%s + open SOs ₱%s + this SO ₱%s).',
                number_format((float) $limit, 2),
                number_format((float) $totalExposure, 2),
                number_format((float) $arBalance, 2),
                number_format((float) $openSoExposure, 2),
                number_format((float) $so->total_amount, 2),
            );
            throw ValidationException::withMessages([
                'credit_limit' => [$msg],
            ]);
        }
    }

    public function list(array $filters): LengthAwarePaginator
    {
        $q = SalesOrder::query()
            ->with(['customer:id,name', 'creator:id,name,role_id'])
            ->withCount('items');

        if (! empty($filters['customer_id'])) {
            $cid = HashIdFilter::decode($filters['customer_id'], Customer::class);
            if ($cid) $q->where('customer_id', $cid);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
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
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
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

            // Resolve every line's unit_price from the active agreement.
            $lines = [];
            $subtotal = 0.0;
            foreach ($data['items'] as $idx => $item) {
                $productId    = (int) $item['product_id'];
                $deliveryDate = Carbon::parse($item['delivery_date']);
                $qty          = (float) $item['quantity'];

                // Resolve at delivery_date — that's the date the price applies to.
                try {
                    $agreement = $this->prices->resolve($customerId, $productId, $deliveryDate);
                } catch (\App\Modules\CRM\Exceptions\NoPriceAgreementException $e) {
                    throw new \App\Modules\CRM\Exceptions\NoPriceAgreementException("items.{$idx}.product_id");
                }
                $unitPrice = (float) $agreement->price;
                $lineTotal = round($qty * $unitPrice, 2);

                $lines[] = [
                    'product_id'         => $productId,
                    'quantity'           => $qty,
                    'unit_price'         => $unitPrice,
                    'total'              => $lineTotal,
                    'quantity_delivered' => 0,
                    'delivery_date'      => $deliveryDate->toDateString(),
                ];
                $subtotal += $lineTotal;
            }

            $vat   = round($subtotal * self::VAT_RATE, 2);
            $total = round($subtotal + $vat, 2);

            $so = SalesOrder::create([
                'so_number'          => $this->sequences->generate('sales_order'),
                'customer_id'        => $customerId,
                'date'               => $orderDate->toDateString(),
                'subtotal'           => $subtotal,
                'vat_amount'         => $vat,
                'total_amount'       => $total,
                'status'             => SalesOrderStatus::Draft->value,
                'payment_terms_days' => $data['payment_terms_days'] ?? 30,
                'delivery_terms'     => $data['delivery_terms'] ?? null,
                'notes'              => $data['notes'] ?? null,
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
        if ($so->status !== SalesOrderStatus::Draft) {
            throw new RuntimeException('Only draft sales orders can be updated.');
        }

        return DB::transaction(function () use ($so, $data) {
            $customerId = (int) ($data['customer_id'] ?? $so->customer_id);
            $subtotal = 0.0;
            $newLines = [];

            foreach ($data['items'] as $idx => $item) {
                $productId    = (int) $item['product_id'];
                $deliveryDate = Carbon::parse($item['delivery_date']);
                $qty          = (float) $item['quantity'];
                try {
                    $agreement = $this->prices->resolve($customerId, $productId, $deliveryDate);
                } catch (\App\Modules\CRM\Exceptions\NoPriceAgreementException $e) {
                    throw new \App\Modules\CRM\Exceptions\NoPriceAgreementException("items.{$idx}.product_id");
                }
                $unitPrice = (float) $agreement->price;
                $lineTotal = round($qty * $unitPrice, 2);
                $newLines[] = [
                    'product_id'         => $productId,
                    'quantity'           => $qty,
                    'unit_price'         => $unitPrice,
                    'total'              => $lineTotal,
                    'quantity_delivered' => 0,
                    'delivery_date'      => $deliveryDate->toDateString(),
                ];
                $subtotal += $lineTotal;
            }
            $vat   = round($subtotal * self::VAT_RATE, 2);
            $total = round($subtotal + $vat, 2);

            $so->update([
                'customer_id'        => $customerId,
                'date'               => $data['date'] ?? $so->date->toDateString(),
                'subtotal'           => $subtotal,
                'vat_amount'         => $vat,
                'total_amount'       => $total,
                'payment_terms_days' => $data['payment_terms_days'] ?? $so->payment_terms_days,
                'delivery_terms'     => $data['delivery_terms'] ?? $so->delivery_terms,
                'notes'              => $data['notes'] ?? $so->notes,
            ]);

            // Replace items wholesale (draft state — no FK ramifications yet).
            $so->items()->delete();
            foreach ($newLines as $line) {
                $so->items()->create($line);
            }

            return $this->show($so->fresh());
        });
    }

    /**
     * Confirm a draft → 'confirmed'. Sprint 6 Task 52 will hook MRP run here.
     * For now we simply flip the status and invariant-check that the SO has lines.
     */
    public function confirm(SalesOrder $so): SalesOrder
    {
        $this->checkCreditLimit($so);

        if ($so->status !== SalesOrderStatus::Draft) {
            throw new RuntimeException('Only draft sales orders can be confirmed.');
        }
        if ($so->items()->count() === 0) {
            throw new RuntimeException('Cannot confirm a sales order with no items.');
        }
        return DB::transaction(function () use ($so) {
            $so->update(['status' => SalesOrderStatus::Confirmed->value]);

            // Sprint 6 Task 52: trigger MRP run synchronously inside the
            // confirmation transaction. Wrapped in try/catch so a broken BOM or
            // missing supplier mapping fails the whole confirm with a 422 — the
            // CRM officer has to fix the data before the SO can advance.
            $engine = $this->mrpEngine();
            if ($engine) {
                $engine->runForSalesOrder($so);
            }

            // Sprint 6 audit §1.7: broadcast on production.dashboard so the
            // chain stage breakdown updates without a manual refetch.
            DB::afterCommit(function () use ($so) {
                $fresh = $so->fresh();
                event(new \App\Modules\CRM\Events\SalesOrderConfirmed($fresh));

                // Series C — Task C4. Real-time chain progress for the
                // SO detail page.
                app(\App\Common\Services\ChainBroadcaster::class)->broadcastFor(
                    $fresh,
                    \App\Modules\CRM\Enums\SalesOrderStatus::Confirmed->value,
                    auth()->user(),
                );
            });

            return $this->show($so->fresh());
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
     * Wraps confirm() which runs MRP (creating WOs + PRs), then attempts
     * capacity scheduling via CapacityPlanningService. Non-fatal if scheduling
     * fails — the WOs still exist as 'planned'.
     */
    public function confirmWithChainResult(SalesOrder $so): array
    {
        // The confirm() call already runs MRP inside its transaction.
        $confirmedSo = $this->confirm($so);

        // Gather what MRP created.
        $confirmedSo->load([
            'mrpPlan',
            'workOrders.product:id,part_number,name',
            'workOrders.machine:id,machine_code,name',
            'workOrders.mold:id,mold_code,name',
        ]);

        $plan = $confirmedSo->mrpPlan;
        $workOrders = $confirmedSo->workOrders;

        // Attempt auto-scheduling via CapacityPlanner.
        $schedulingResult = ['scheduled' => [], 'conflicts' => []];
        $planner = $this->capacityPlanner();
        if ($planner && $workOrders->isNotEmpty()) {
            $plannedWoIds = $workOrders
                ->where('status', \App\Modules\Production\Enums\WorkOrderStatus::Planned)
                ->pluck('id')
                ->all();
            if (!empty($plannedWoIds)) {
                try {
                    $schedulingResult = $planner->run($plannedWoIds);
                } catch (\Throwable $e) {
                    // Scheduling failure is non-fatal — WOs still exist as planned.
                }
            }
        }

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
                'work_orders' => $woSummaries,
                'scheduling_conflicts' => $schedulingResult['conflicts'],
            ],
        ];
    }

    public function cancel(SalesOrder $so, ?string $reason = null): SalesOrder
    {
        if (! $so->is_cancellable) {
            throw new RuntimeException('This sales order cannot be cancelled at its current status.');
        }
        return DB::transaction(function () use ($so, $reason) {
            $so->update([
                'status' => SalesOrderStatus::Cancelled->value,
                'notes'  => trim(($so->notes ?? '') . "\n\n[Cancelled" . ($reason ? ': ' . $reason : '') . ']'),
            ]);

            // Sprint 6 audit §1.2: cascade through the chain.
            //  1. Supersede the active MRP plan (status='cancelled').
            //  2. Cancel any planned/confirmed/paused WO linked to this SO via
            //     the MRP plan; in_progress/completed/closed WOs are left
            //     alone — the operator must finish or cancel them manually.
            //     WorkOrderService::cancel() releases each WO's reservations.
            $plan = \App\Modules\MRP\Models\MrpPlan::where('sales_order_id', $so->id)
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
                    ->where('sales_order_id', $so->id)
                    ->whereIn('status', $cancellableStatuses)
                    ->lockForUpdate()
                    ->get();
                foreach ($linkedWos as $wo) {
                    $woService->cancel($wo, $reason ?? "Sales order {$so->so_number} cancelled");
                }
            }

            // Series C — Task C4. Real-time chain progress.
            DB::afterCommit(function () use ($so) {
                app(\App\Common\Services\ChainBroadcaster::class)->broadcastFor(
                    $so->fresh(),
                    \App\Modules\CRM\Enums\SalesOrderStatus::Cancelled->value,
                    auth()->user(),
                );
            });

            return $this->show($so->fresh());
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
        if ($so->status !== SalesOrderStatus::Draft) {
            throw new RuntimeException('Only draft sales orders can be deleted.');
        }
        $so->delete();
    }

    // ─── C-2: Lifecycle transitions wired from WO / Delivery / Invoice ──────
    //
    // These helpers are called from listeners and sibling services that do
    // NOT own SO state. They must be idempotent, lock-aware, and gated so an
    // upstream operation never blows up because of a stale/invalid transition.

    public function markInProduction(?int $salesOrderId): void
    {
        $this->transitionTo($salesOrderId, SalesOrderStatus::InProduction);
    }

    public function markPartiallyDelivered(?int $salesOrderId): void
    {
        $this->transitionTo($salesOrderId, SalesOrderStatus::PartiallyDelivered);
    }

    public function markDelivered(?int $salesOrderId): void
    {
        $this->transitionTo($salesOrderId, SalesOrderStatus::Delivered);
    }

    public function markInvoiced(?int $salesOrderId): void
    {
        $this->transitionTo($salesOrderId, SalesOrderStatus::Invoiced);
    }

    private function transitionTo(?int $salesOrderId, SalesOrderStatus $target): void
    {
        if ($salesOrderId === null) {
            return;
        }

        $broadcastFromTo = null;

        DB::transaction(function () use ($salesOrderId, $target, &$broadcastFromTo) {
            $so = SalesOrder::lockForUpdate()->find($salesOrderId);
            if (! $so) {
                return;
            }

            $currentValue = $so->status?->value;

            // Idempotent: already at the target state.
            if ($currentValue === $target->value) {
                return;
            }

            $allowed = self::ALLOWED_TRANSITIONS[$currentValue ?? ''] ?? [];
            if (! in_array($target->value, $allowed, true)) {
                Log::debug('SalesOrder transition skipped', [
                    'sales_order_id' => $so->id,
                    'from'           => $currentValue,
                    'to'             => $target->value,
                ]);
                return;
            }

            $so->update(['status' => $target->value]);
            $broadcastFromTo = [$so->fresh(), $currentValue, $target->value];
        });

        if ($broadcastFromTo !== null) {
            [$so, $from, $to] = $broadcastFromTo;
            DB::afterCommit(function () use ($so, $to) {
                app(\App\Common\Services\ChainBroadcaster::class)->broadcastFor($so, $to);
            });
        }
    }

    /**
     * Chain payload — qc_outgoing derived from real Inspection state (H-4);
     * other 5 stages still derive from the SO's own status field.
     */
    public function chain(SalesOrder $so): array
    {
        $confirmedDate = $so->status !== SalesOrderStatus::Draft ? $so->updated_at?->toDateString() : null;
        $qc = $this->deriveOutgoingQcStage($so);

        return [
            ['key' => 'order_entered', 'label' => 'Order Entered',
             'date' => $so->created_at?->toDateString(),
             'state' => 'done'],
            ['key' => 'mrp_planned', 'label' => 'MRP Planned',
             'date' => $confirmedDate,
             'state' => $so->mrp_plan_id ? 'done' : ($so->status === SalesOrderStatus::Confirmed ? 'active' : 'pending')],
            ['key' => 'in_production', 'label' => 'In Production',
             'date' => null,
             'state' => $so->status === SalesOrderStatus::InProduction ? 'active' : 'pending'],
            ['key' => 'qc_outgoing', 'label' => 'QC Outgoing', 'date' => $qc['date'], 'state' => $qc['state']],
            ['key' => 'delivered', 'label' => 'Delivered', 'date' => null,
             'state' => in_array($so->status, [SalesOrderStatus::Delivered, SalesOrderStatus::Invoiced], true) ? 'done' : 'pending'],
            ['key' => 'invoiced', 'label' => 'Invoiced', 'date' => null,
             'state' => $so->status === SalesOrderStatus::Invoiced ? 'done' : 'pending'],
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
