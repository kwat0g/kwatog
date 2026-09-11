<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
use App\Modules\Dashboard\Support\PanelGate;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4.1 extraction — Purchasing Officer dashboard.
 * Owns: purchasing, purchasingPrActionQueue, prItemCount,
 *       purchasingPoPipeline, purchasingTopSuppliers, purchasingUpcomingDeliveries.
 */
class PurchasingDashboardService
{
    use DashboardQueries;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PanelGate $gate,
    ) {}

    private const CACHE_TTL = 30;

    public function purchasing(User $user): array
    {
        return Cache::remember("dashboard:purchasing:{$user->id}", self::CACHE_TTL, function () use ($user) {
            return [
                'kpis' => $this->gate->kpis($user, [
                    ['purchasing.view', fn () => $this->kpi('PRs Pending Action', (string) $this->safeCount('purchase_requests', fn ($q) => $q->where('status', 'pending')), 'count')],
                    ['purchasing.view', fn () => $this->kpi('Open POs', (string) $this->safeCount('purchase_orders', fn ($q) => $q->whereIn('status', PurchaseOrderStatus::open())), 'count')],
                    // Overdue against po.expected_delivery_date — a purchasing
                    // reading, not a supply-chain one.
                    ['purchasing.view', fn () => $this->kpi('Overdue Deliveries', (string) $this->safeCount('purchase_orders', fn ($q) => $q
                        ->whereIn('status', PurchaseOrderStatus::open())
                        ->where('expected_delivery_date', '<', today())), 'count')],
                    ['purchasing.suppliers.performance.view', fn () => $this->kpi('Suppliers Due Review', (string) $this->suppliersDueReview(), 'count')],
                ]),
                'panels' => $this->gate->panels($user, [
                    // A configured horizon, not data.
                    'delivery_horizon_days' => [null,                                     fn () => $this->settings->requiredInt('dashboard.widgets.delivery_horizon_days', 0)],
                    'pr_action_queue'      => ['purchasing.view',                         fn () => $this->purchasingPrActionQueue()],
                    'po_pipeline'          => ['purchasing.view',                         fn () => $this->purchasingPoPipeline()],
                    // Scored suppliers by name — its own grant, which finance
                    // also holds for the vendor-performance review.
                    'supplier_performance' => ['purchasing.suppliers.performance.view',   fn () => $this->purchasingTopSuppliers()],
                    // purchase_orders + vendor names, despite the name.
                    'upcoming_deliveries'  => ['purchasing.view',                         fn () => $this->purchasingUpcomingDeliveries()],
                ]),
            ];
        });
    }

    /**
     * @return array<int, array{id: string, pr_number: string, department: string, items_count: int, estimated_total: string, urgency: string, days_waiting: int}>
     */
    private function purchasingPrActionQueue(): array
    {
        if (! Schema::hasTable('purchase_requests')) return [];
        return DB::table('purchase_requests as pr')
            ->leftJoin('departments as d', 'd.id', '=', 'pr.department_id')
            ->where('pr.status', 'pending')
            ->select('pr.id', 'pr.pr_number', 'd.name as department_name', 'pr.priority', 'pr.created_at')
            ->orderBy('pr.priority')
            ->orderBy('pr.created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'              => app('hashids')->encode((int) $r->id),
                'pr_number'       => $r->pr_number,
                'department'      => $r->department_name ?? '—',
                'items_count'     => $this->prItemCount((int) $r->id),
                'estimated_total' => $this->prEstimatedTotal((int) $r->id),
                'urgency'         => $r->priority ?? PurchaseRequestPriority::Normal->value,
                'urgency_label'   => PurchaseRequestPriority::tryFrom((string) ($r->priority ?? $this->settings->get('purchasing.purchase_request.default_priority', '')))?->label() ?? (string) ($r->priority ?? $this->settings->get('purchasing.purchase_request.default_priority', '')),
                'days_waiting'    => $r->created_at ? (int) Carbon::parse((string) $r->created_at)->diffInDays(now(), true) : 0,
            ])
            ->all();
    }

    private function prItemCount(int $prId): int
    {
        if (! Schema::hasTable('purchase_request_items')) return 0;
        return (int) DB::table('purchase_request_items')->where('purchase_request_id', $prId)->count();
    }

    private function prEstimatedTotal(int $prId): string
    {
        if (! Schema::hasTable('purchase_request_items')) return '0.00';

        return number_format((float) DB::table('purchase_request_items')
            ->where('purchase_request_id', $prId)
            ->selectRaw('COALESCE(SUM(quantity * estimated_unit_price), 0) as total')
            ->value('total'), 2, '.', '');
    }

    /**
     * @return array<int, array{status: string, count: int}>
     */
    private function purchasingPoPipeline(): array
    {
        if (! Schema::hasTable('purchase_orders')) return [];
        $statuses = [
            PurchaseOrderStatus::Draft,
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::Acknowledged,
            PurchaseOrderStatus::SupplierProposed,
            PurchaseOrderStatus::SupplierDeclined,
            PurchaseOrderStatus::PartiallyReceived,
            PurchaseOrderStatus::Received,
            PurchaseOrderStatus::Closed,
        ];
        $rows     = [];
        foreach ($statuses as $status) {
            $c = $this->safeCount('purchase_orders', fn ($q) => $q->where('status', $status->value));
            if ($c > 0) {
                $rows[] = ['status' => $status->value, 'status_label' => $status->label(), 'count' => $c];
            }
        }
        return $rows;
    }

    /**
     * @return array<int, array{name: string, overall_score: string, tier: string|null}>
     */
    private function purchasingTopSuppliers(): array
    {
        if (! Schema::hasTable('supplier_performance_snapshots') || ! Schema::hasTable('vendors')) return [];
        $latestPeriod = $this->latestSupplierSnapshotPeriod();
        if (! $latestPeriod) return [];
        return DB::table('supplier_performance_snapshots as sps')
            ->join('vendors as v', 'v.id', '=', 'sps.vendor_id')
            ->where('sps.period_year', $latestPeriod->period_year)
            ->where('sps.period_month', $latestPeriod->period_month)
            ->orderByDesc('sps.overall_score')
            ->limit(5)
            ->select('v.name', 'sps.overall_score', 'sps.tier')
            ->get()
            ->map(fn ($r) => [
                'name'          => $r->name,
                'overall_score' => number_format((float) $r->overall_score, 1),
                'tier'          => $r->tier,
            ])
            ->all();
    }

    private function suppliersDueReview(): int
    {
        if (! Schema::hasTable('supplier_performance_snapshots')) return 0;
        $latestPeriod = $this->latestSupplierSnapshotPeriod();
        if (! $latestPeriod) return 0;

        $threshold = $this->settings->requiredFloat('purchasing.supplier_score.tier_c_min', 0, 100);

        return (int) DB::table('supplier_performance_snapshots')
            ->where('period_year', $latestPeriod->period_year)
            ->where('period_month', $latestPeriod->period_month)
            ->whereNotNull('overall_score')
            ->where('overall_score', '<', $threshold)
            ->distinct()
            ->count('vendor_id');
    }

    private function latestSupplierSnapshotPeriod(): ?object
    {
        if (! Schema::hasTable('supplier_performance_snapshots')) return null;

        return DB::table('supplier_performance_snapshots')
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->select('period_year', 'period_month')
            ->first();
    }

    /**
     * @return array<int, array{id: string, po_number: string, vendor: string, items_count: int, expected_date: string|null, status: string}>
     */
    private function purchasingUpcomingDeliveries(): array
    {
        if (! Schema::hasTable('purchase_orders') || ! Schema::hasTable('vendors')) return [];

        $itemsCountSub = Schema::hasTable('purchase_order_items')
            ? '(SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id)'
            : '0';

        $deliveryDays = $this->settings->requiredInt('dashboard.widgets.delivery_horizon_days', 0);
        return DB::table('purchase_orders as po')
            ->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->whereIn('po.status', PurchaseOrderStatus::receivable())
            ->whereNotNull('po.expected_delivery_date')
            ->whereBetween('po.expected_delivery_date', [today(), today()->addDays($deliveryDays)])
            ->select('po.id', 'po.po_number', 'v.name as vendor_name', 'po.expected_delivery_date', 'po.status',
                DB::raw("{$itemsCountSub} as items_count"))
            ->orderBy('po.expected_delivery_date')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'id'            => app('hashids')->encode((int) $r->id),
                'po_number'     => $r->po_number,
                'vendor'        => $r->vendor_name ?? '—',
                'items_count'   => (int) $r->items_count,
                'expected_date' => $r->expected_delivery_date,
                'status'        => $r->status,
                'status_label'  => PurchaseOrderStatus::tryFrom((string) $r->status)?->label() ?? (string) $r->status,
            ])
            ->all();
    }
}
