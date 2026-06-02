<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
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

    private const CACHE_TTL = 30;

    public function purchasing(User $user): array
    {
        return Cache::remember("dashboard:purchasing:{$user->id}", self::CACHE_TTL, function () {
            $prsPending   = $this->safeCount('purchase_requests', fn ($q) => $q->where('status', 'pending'));
            $openPos      = $this->safeCount('purchase_orders', fn ($q) => $q->whereIn('status', ['draft', 'approved', 'sent']));
            $overdue      = $this->safeCount('purchase_orders', fn ($q) => $q
                ->whereIn('status', ['approved', 'sent'])
                ->where('expected_delivery_date', '<', today()));
            $suppliersDue = $this->safeCount('supplier_performance_snapshots', fn ($q) => $q
                ->where('overall_score', '<', 80));

            return [
                'kpis' => [
                    $this->kpi('PRs Pending Action',   (string) $prsPending,   'count'),
                    $this->kpi('Open POs',              (string) $openPos,      'count'),
                    $this->kpi('Overdue Deliveries',    (string) $overdue,      'count'),
                    $this->kpi('Suppliers Due Review',  (string) $suppliersDue, 'count'),
                ],
                'panels' => [
                    'pr_action_queue'      => $this->purchasingPrActionQueue(),
                    'po_pipeline'          => $this->purchasingPoPipeline(),
                    'supplier_performance' => $this->purchasingTopSuppliers(),
                    'upcoming_deliveries'  => $this->purchasingUpcomingDeliveries(),
                ],
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
            ->select('pr.id', 'pr.pr_number', 'd.name as department_name', 'pr.total_estimated_amount', 'pr.urgency', 'pr.created_at')
            ->orderBy('pr.urgency')
            ->orderBy('pr.created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'              => app('hashids')->encode((int) $r->id),
                'pr_number'       => $r->pr_number,
                'department'      => $r->department_name ?? '—',
                'items_count'     => $this->prItemCount((int) $r->id),
                'estimated_total' => number_format((float) ($r->total_estimated_amount ?? 0), 2, '.', ''),
                'urgency'         => $r->urgency ?? 'normal',
                'days_waiting'    => $r->created_at ? (int) Carbon::parse((string) $r->created_at)->diffInDays(now()) : 0,
            ])
            ->all();
    }

    private function prItemCount(int $prId): int
    {
        if (! Schema::hasTable('purchase_request_items')) return 0;
        return (int) DB::table('purchase_request_items')->where('purchase_request_id', $prId)->count();
    }

    /**
     * @return array<int, array{status: string, count: int}>
     */
    private function purchasingPoPipeline(): array
    {
        if (! Schema::hasTable('purchase_orders')) return [];
        $statuses = ['draft', 'approved', 'sent', 'partial', 'received', 'closed'];
        $rows     = [];
        foreach ($statuses as $s) {
            $c = $this->safeCount('purchase_orders', fn ($q) => $q->where('status', $s));
            if ($c > 0) {
                $rows[] = ['status' => $s, 'count' => $c];
            }
        }
        return $rows;
    }

    /**
     * @return array<int, array{name: string, overall_score: string}>
     */
    private function purchasingTopSuppliers(): array
    {
        if (! Schema::hasTable('supplier_performance_snapshots') || ! Schema::hasTable('vendors')) return [];
        $latestPeriod = DB::table('supplier_performance_snapshots')
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->select('period_year', 'period_month')->first();
        if (! $latestPeriod) return [];
        return DB::table('supplier_performance_snapshots as sps')
            ->join('vendors as v', 'v.id', '=', 'sps.vendor_id')
            ->where('sps.period_year', $latestPeriod->period_year)
            ->where('sps.period_month', $latestPeriod->period_month)
            ->orderByDesc('sps.overall_score')
            ->limit(5)
            ->select('v.name', 'sps.overall_score')
            ->get()
            ->map(fn ($r) => [
                'name'          => $r->name,
                'overall_score' => number_format((float) $r->overall_score, 1),
            ])
            ->all();
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

        return DB::table('purchase_orders as po')
            ->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->whereIn('po.status', ['approved', 'sent', 'partial'])
            ->whereNotNull('po.expected_delivery_date')
            ->whereBetween('po.expected_delivery_date', [today(), today()->addDays(7)])
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
            ])
            ->all();
    }
}
