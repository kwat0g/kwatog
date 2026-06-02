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
 * P4.1 extraction — Plant Manager dashboard.
 * Owns: plantManager + plantFinancialSnapshot + range/revenue/production/OEE/OTD helpers.
 * Shared helpers (kpi, safeCount, safeSum, cashBalance, chainStageBreakdown, alerts,
 * machineUtilization, defectPareto) come from DashboardQueries trait.
 */
class PlantManagerDashboardService
{
    use DashboardQueries;

    private const CACHE_TTL = 30;

    public function plantManager(User $user, string $range = 'week'): array
    {
        $range = in_array($range, ['today', 'week', 'month', 'quarter'], true) ? $range : 'week';

        return Cache::remember("dashboard:plant_manager:{$user->id}:{$range}", self::CACHE_TTL, function () use ($range) {
            [$start, $end, $label] = $this->rangeBounds($range);

            return [
                'kpis' => [
                    $this->kpi("Revenue · {$label}",    $this->revenueInRange($start, $end),    'PHP'),
                    $this->kpi("Production · {$label}", $this->productionInRange($start, $end), 'units'),
                    $this->kpi('OEE · Today',           $this->oeeToday(),                       'pct'),
                    $this->kpi('On-Time Delivery',      $this->otdRate(),                        'pct'),
                ],
                'panels' => [
                    'chain_stages'       => $this->chainStageBreakdown(),
                    'alerts'             => $this->alerts(),
                    'machine_util'       => $this->machineUtilization(),
                    'defect_pareto'      => $this->defectPareto(),
                    'financial_snapshot' => $this->plantFinancialSnapshot(),
                    'range'              => $range,
                ],
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

        $arOutstanding = $this->safeSum('invoices', 'balance', fn ($q) => $q->whereIn('status', ['unpaid', 'partial']));
        $apOutstanding = $this->safeSum('bills',    'balance', fn ($q) => $q->whereIn('status', ['unpaid', 'partial']));

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

    private function oeeToday(): string
    {
        if (! Schema::hasTable('work_order_outputs')) return '0.0';
        $good = (int) DB::table('work_order_outputs')
            ->whereDate('recorded_at', today())->sum('good_count');
        $rej  = (int) DB::table('work_order_outputs')
            ->whereDate('recorded_at', today())->sum('reject_count');
        if ($good + $rej === 0) return '0.0';
        return number_format(($good * 100.0) / max(1, $good + $rej), 1, '.', '');
    }

    private function otdRate(): string
    {
        if (! Schema::hasTable('deliveries')) return '0.0';
        $base = fn () => DB::table('deliveries')
            ->whereIn('status', ['delivered', 'confirmed'])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [now()->subMonth(), now()]);

        $total = (int) $base()->count();
        if ($total === 0) return '0.0';

        $onTime = (int) $base()
            ->whereRaw('DATE(delivered_at) <= scheduled_date')
            ->count();

        return number_format(($onTime * 100.0) / $total, 1, '.', '');
    }
}
