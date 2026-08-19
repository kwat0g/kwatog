<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
use App\Modules\Dashboard\Support\PanelGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4.1 extraction — Plant Manager dashboard.
 * Owns: plantManager + plantFinancialSnapshot + range/revenue/production/OEE/OTD helpers.
 * Shared helpers (kpi, safeCount, safeSum, cashBalance, chainStageBreakdown, alerts,
 * machineUtilization, defectPareto) come from DashboardQueries trait.
 *
 * Every panel and KPI declares the permission that lets it render (PanelGate).
 * `dashboard.plant_manager.view` opens the PAGE; it does not entitle the viewer
 * to every domain the page draws from. This mattered most for the financial
 * snapshot (Task D2): it reports cash, AR, AP and posted revenue, and the only
 * seeded role holding this dashboard — production_manager — has no `accounting.*`
 * grant whatsoever, so the page was handing it a finance read its own module
 * refuses. Each gate below is the SAME permission the equivalent widget uses
 * over the same data (DashboardWidgetSeeder), so a reading cannot be authorized
 * on one surface and refused on the other.
 */
class PlantManagerDashboardService
{
    use DashboardQueries;

    private const CACHE_TTL = 30;

    public function __construct(private readonly PanelGate $gate) {}

    public function plantManager(User $user, string $range = 'week'): array
    {
        $range = in_array($range, ['today', 'week', 'month', 'quarter'], true) ? $range : 'week';

        // Cache key already carries the user id, so a per-viewer panel set is
        // cache-safe — two roles never share an entry.
        return Cache::remember("dashboard:plant_manager:{$user->id}:{$range}", self::CACHE_TTL, function () use ($user, $range) {
            [$start, $end, $label] = $this->rangeBounds($range);

            return [
                'kpis' => $this->gate->kpis($user, [
                    // Money, even as a single figure, is finance's to disclose.
                    ['accounting.dashboard.view', fn () => $this->kpi("Revenue · {$label}",    $this->revenueInRange($start, $end),    $this->functionalCurrency())],
                    // Non-financial plant rates: one aggregate each, no row, no
                    // amount, no counterparty. They are the reason this page
                    // exists, so they ride the page grant rather than a module
                    // read their only holder does not have (production_manager
                    // holds no supply_chain grant, which would have silently
                    // stripped On-Time Delivery from the plant dashboard).
                    [null,                        fn () => $this->kpi("Production · {$label}", $this->productionInRange($start, $end), 'units')],
                    [null,                        fn () => $this->kpi('OEE · Today',           $this->oeeToday(),                       'pct')],
                    [null,                        fn () => $this->kpi('On-Time Delivery',      $this->otdRate(),                        'pct')],
                ]),
                'panels' => $this->gate->panels($user, [
                    // Row-level from here down: each is a list of documents,
                    // machines or defects, so each follows its module's grant.
                    'chain_stages'       => ['dashboard.view_bottlenecks', fn () => $this->chainStageBreakdown()],
                    'alerts'             => ['alerts.view',                fn () => $this->alerts()],
                    'machine_util'       => ['production.dashboard.view',  fn () => $this->machineUtilization()],
                    'defect_pareto'      => ['quality.view',               fn () => $this->defectPareto()],
                    // Cash, AR, AP and posted revenue. production_manager — the
                    // only seeded holder of this dashboard — has no accounting
                    // grant at all, so this panel was the leak.
                    'financial_snapshot' => ['accounting.dashboard.view',  fn () => $this->plantFinancialSnapshot()],
                    // Not data — the echo of the caller's own range selection.
                    'range'              => [null,                         fn () => $range],
                ]),
            ];
        });
    }

    /**
     * Task D2 — Financial snapshot for the Plant Manager dashboard.
     *
     * @return array{cash_balance: string, ar_outstanding: string, ap_outstanding: string, revenue_mtd: string, je_draft_count: int}
     */
    private function plantFinancialSnapshot(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $cashBalance = $this->cashBalance();

        $arOutstanding = $this->safeSum('invoices', 'balance', fn ($q) => $q->whereIn('status', [InvoiceStatus::Finalized->value, InvoiceStatus::Partial->value]));
        $apOutstanding = $this->safeSum('bills',    'balance', fn ($q) => $q->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value]));

        $revenueMtd = '0.00';
        if (Schema::hasTable('journal_entry_lines') && Schema::hasTable('accounts')) {
            $rev = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jel.account_id')
                ->where('je.status', 'posted')
                ->where('a.type', 'revenue')
                ->whereBetween('je.date', [$monthStart, $monthEnd])
                ->selectRaw('COALESCE(SUM(jel.credit) - SUM(jel.debit), 0) as rev')
                ->value('rev');
            $revenueMtd = number_format((float) ($rev ?? 0), 2, '.', '');
        }

        $jeDraftCount = $this->safeCount('journal_entries', fn ($q) => $q->where('status', 'draft'));

        return [
            'cash_balance'   => $cashBalance,
            'ar_outstanding' => $arOutstanding,
            'ap_outstanding' => $apOutstanding,
            'revenue_mtd'    => $revenueMtd,
            'je_draft_count' => $jeDraftCount,
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon, 2: string}
     */
    private function rangeBounds(string $range): array
    {
        return match ($range) {
            'today'   => [now()->startOfDay(),     now()->endOfDay(),     'Today'],
            'month'   => [now()->startOfMonth(),   now()->endOfMonth(),   'Month'],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter(), 'Quarter'],
            default   => [now()->startOfWeek(),    now()->endOfWeek(),    'Week'],
        };
    }

    private function revenueInRange(\Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end): string
    {
        if (! Schema::hasTable('invoices')) return '0.00';
        $sum = (float) DB::table('invoices')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->sum('total_amount');
        return number_format($sum, 2, '.', '');
    }

    private function productionInRange(\Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end): string
    {
        if (! Schema::hasTable('work_order_outputs')) return '0';
        return (string) (int) DB::table('work_order_outputs')
            ->whereBetween('recorded_at', [$start, $end])
            ->sum('good_count');
    }

    private function oeeToday(): ?string
    {
        if (! Schema::hasTable('work_order_outputs')) return null;
        $good = (int) DB::table('work_order_outputs')
            ->whereDate('recorded_at', today())->sum('good_count');
        $rej  = (int) DB::table('work_order_outputs')
            ->whereDate('recorded_at', today())->sum('reject_count');
        if ($good + $rej === 0) return null;
        return number_format(($good * 100.0) / max(1, $good + $rej), 1, '.', '');
    }

    private function otdRate(): ?string
    {
        if (! Schema::hasTable('deliveries')) return null;
        $base = fn () => DB::table('deliveries')
            ->whereIn('status', ['delivered', 'confirmed'])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [now()->subMonth(), now()]);

        $total = (int) $base()->count();
        if ($total === 0) return null;

        $onTime = (int) $base()
            ->whereRaw('DATE(delivered_at) <= scheduled_date')
            ->count();

        return number_format(($onTime * 100.0) / $total, 1, '.', '');
    }
}
