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
 * P4.1 extraction — PPC (Production Planning & Control) + Accounting dashboards.
 * Grouped together because the Accounting dashboard is small (4 KPIs, 2 panels)
 * and uses some of the same financial helpers; keeping them here avoids a
 * nearly-empty class. If Accounting grows, extract to AccountingDashboardService.
 * Owns: ppc, accounting, all ppc/mold/WO/gantt/capacity helpers, JE/AR helpers.
 */
class PpcDashboardService
{
    use DashboardQueries;

    private const CACHE_TTL = 30;

    public function ppc(User $user): array
    {
        return Cache::remember("dashboard:ppc:{$user->id}", self::CACHE_TTL, function () {
            $activeWos    = $this->safeCount('work_orders', fn ($q) => $q->whereIn('status', ['planned', 'confirmed', 'in_progress', 'paused']));
            $shortages    = $this->safeCount('purchase_requests', fn ($q) => $q->where('is_auto_generated', true)->where('status', 'pending'));
            $breakdowns   = $this->safeCount('machine_downtimes', fn ($q) => $q->whereNull('end_time')->where('category', 'breakdown'));
            $moldsAtLimit = $this->moldsNearingLimit();

            $mrpLastRun   = $this->mrpLastRun();
            $unplannedWos = $this->unplannedWorkOrders();
            $capacityUsed = $this->capacityUtilization();

            return [
                'kpis' => [
                    $this->kpi('Active WOs',         (string) $activeWos,    'count'),
                    $this->kpi('Material Shortages',  (string) $shortages,   'count'),
                    $this->kpi('Capacity Used',       $capacityUsed,          'pct'),
                    $this->kpi('Molds ≥ 80%',        (string) $moldsAtLimit, 'count'),
                ],
                'panels' => [
                    'chain_stages'         => $this->chainStageBreakdown(),
                    'alerts'               => $this->alerts(),
                    'machine_util'         => $this->machineUtilization(),
                    'mrp_last_run'         => $mrpLastRun,
                    'unplanned_wos'        => $unplannedWos,
                    'production_gantt'     => $this->productionGantt(),
                    'mrp_shortages'        => $this->ppcMrpShortages(),
                    'machine_availability' => $this->machineAvailabilityGrid(),
                    'wo_status_breakdown'  => $this->woStatusBreakdown(),
                ],
            ];
        });
    }

    public function accounting(User $user): array
    {
        return Cache::remember("dashboard:accounting:{$user->id}", self::CACHE_TTL, function () {
            $cashBalance = $this->cashBalance();
            $arOpen      = $this->safeSum('invoices', 'balance', fn ($q) => $q->whereIn('status', ['unpaid', 'partial']));
            $apOpen      = $this->safeSum('bills',    'balance', fn ($q) => $q->whereIn('status', ['unpaid', 'partial']));
            $jeDraft     = $this->safeCount('journal_entries', fn ($q) => $q->where('status', 'draft'));

            return [
                'kpis' => [
                    $this->kpi('Cash Balance',   $cashBalance,       'PHP'),
                    $this->kpi('AR Outstanding',  $arOpen,           'PHP'),
                    $this->kpi('AP Outstanding',  $apOpen,           'PHP'),
                    $this->kpi('Draft JEs',       (string) $jeDraft, 'count'),
                ],
                'panels' => [
                    'recent_jes'     => $this->recentJournalEntries(),
                    'top_overdue_ar' => $this->topOverdueArCustomers(),
                ],
            ];
        });
    }

    /* ─── D3 — PPC helpers ─── */

    private function moldsNearingLimit(): int
    {
        if (! Schema::hasTable('molds')) return 0;
        return (int) DB::table('molds')
            ->whereRaw('current_shot_count >= (max_shots_before_maintenance * 0.8)')
            ->count();
    }

    private function mrpLastRun(): string
    {
        if (! Schema::hasTable('mrp_runs')) return '—';
        $row = DB::table('mrp_runs')->orderByDesc('created_at')->first(['created_at']);
        if (! $row) return '—';
        return $row->created_at ? Carbon::parse((string) $row->created_at)->diffForHumans() : '—';
    }

    private function unplannedWorkOrders(): int
    {
        return $this->safeCount('work_orders', fn ($q) => $q->where('status', 'planned'));
    }

    private function capacityUtilization(): string
    {
        if (! Schema::hasTable('machines') || ! Schema::hasTable('work_orders')) return '0';
        $total = (int) DB::table('machines')->count();
        if ($total === 0) return '0';
        $busy = (int) DB::table('machines')->whereNotNull('current_work_order_id')->count();
        return (string) round(($busy * 100) / $total);
    }

    /**
     * @return array<int, array{machine: string, day: string, status: string, wo_number: string|null}>
     */
    private function productionGantt(): array
    {
        if (! Schema::hasTable('work_orders') || ! Schema::hasTable('machines')) return [];

        $machines = DB::table('machines')->select('id', 'machine_code')->orderBy('machine_code')->limit(8)->get();
        $wos = DB::table('work_orders')
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->whereNotNull('machine_id')
            ->whereNotNull('planned_start')
            ->whereNotNull('planned_end')
            ->get(['machine_id', 'planned_start', 'planned_end', 'status', 'wo_number']);

        $today = now()->startOfDay();
        $days  = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $today->copy()->addDays($i)->toDateString();
        }

        $rows = [];
        foreach ($machines as $m) {
            foreach ($days as $d) {
                $status   = 'available';
                $woNumber = null;
                foreach ($wos as $wo) {
                    if ((int) $wo->machine_id !== (int) $m->id) continue;
                    if (Carbon::parse((string) $wo->planned_start)->toDateString() <= $d
                        && Carbon::parse((string) $wo->planned_end)->toDateString() >= $d) {
                        $status   = $wo->status === 'in_progress' ? 'running' : 'planned';
                        $woNumber = $wo->wo_number;
                        break;
                    }
                }
                $rows[] = ['machine' => $m->machine_code, 'day' => $d, 'status' => $status, 'wo_number' => $woNumber];
            }
        }
        return $rows;
    }

    /**
     * @return array<int, array{item_code: string, item_name: string, shortage: string, urgency: string, pr_status: string|null}>
     */
    private function ppcMrpShortages(): array
    {
        if (! Schema::hasTable('purchase_requests') || ! Schema::hasTable('purchase_request_items') || ! Schema::hasTable('items')) {
            return [];
        }
        return DB::table('purchase_requests as pr')
            ->join('purchase_request_items as pri', 'pri.purchase_request_id', '=', 'pr.id')
            ->join('items', 'items.id', '=', 'pri.item_id')
            ->where('pr.is_auto_generated', true)
            ->where('pr.status', 'pending')
            ->select('items.code as item_code', 'items.name as item_name', 'pri.quantity', 'pr.pr_number', 'pr.status as pr_status')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'item_code'  => $r->item_code,
                'item_name'  => $r->item_name,
                'shortage'   => (string) ($r->quantity ?? '0'),
                'urgency'    => 'urgent',
                'pr_status'  => $r->pr_status,
            ])
            ->all();
    }

    /**
     * @return array<int, array{machine: string, date: string, label: string, status: string}>
     */
    private function machineAvailabilityGrid(): array
    {
        if (! Schema::hasTable('machines') || ! Schema::hasTable('work_orders')) return [];

        $machines = DB::table('machines')
            ->select('id', 'machine_code')
            ->orderBy('machine_code')
            ->limit(12)
            ->get();
        if ($machines->isEmpty()) return [];

        $start = now()->startOfDay();
        $end   = $start->copy()->addDays(6)->endOfDay();

        $wos = DB::table('work_orders')
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->whereNotNull('machine_id')
            ->whereNotNull('planned_start')
            ->whereNotNull('planned_end')
            ->where('planned_start', '<=', $end)
            ->where('planned_end', '>=', $start)
            ->get(['machine_id', 'planned_start', 'planned_end']);

        $maint = collect();
        if (Schema::hasTable('maintenance_schedules')) {
            $maint = DB::table('maintenance_schedules')
                ->where('maintainable_type', 'machine')
                ->where('is_active', true)
                ->whereNotNull('next_due_at')
                ->whereBetween('next_due_at', [$start, $end])
                ->get(['maintainable_id', 'next_due_at']);
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $start->copy()->addDays($i);
        }

        $rows = [];
        foreach ($machines as $m) {
            foreach ($days as $day) {
                $dayStart = $day->copy()->startOfDay();
                $dayEnd   = $day->copy()->endOfDay();

                $isMaint = $maint->contains(fn ($s) => (int) $s->maintainable_id === (int) $m->id
                    && Carbon::parse((string) $s->next_due_at)->betweenIncluded($dayStart, $dayEnd));

                $isBusy = $wos->contains(fn ($wo) => (int) $wo->machine_id === (int) $m->id
                    && Carbon::parse((string) $wo->planned_start) <= $dayEnd
                    && Carbon::parse((string) $wo->planned_end) >= $dayStart);

                $status = $isMaint ? 'maintenance' : ($isBusy ? 'busy' : 'available');

                $rows[] = [
                    'machine' => $m->machine_code,
                    'date'    => $day->toDateString(),
                    'label'   => $day->format('D'),
                    'status'  => $status,
                ];
            }
        }
        return $rows;
    }

    /**
     * @return array<int, array{status: string, count: int}>
     */
    private function woStatusBreakdown(): array
    {
        if (! Schema::hasTable('work_orders')) return [];
        $statuses = ['planned', 'confirmed', 'in_progress', 'paused', 'completed'];
        $rows     = [];
        foreach ($statuses as $s) {
            $rows[] = ['status' => $s, 'count' => $this->safeCount('work_orders', fn ($q) => $q->where('status', $s))];
        }
        return $rows;
    }

    /* ─── Accounting helpers ─── */

    private function recentJournalEntries(): array
    {
        if (! Schema::hasTable('journal_entries')) return [];
        return DB::table('journal_entries')
            ->orderByDesc('id')->limit(8)->get()
            ->map(fn ($je) => [
                'id'           => app('hashids')->encode((int) $je->id),
                'entry_number' => $je->entry_number,
                'status'       => $je->status,
                'total_debit'  => (string) $je->total_debit,
                'date'         => $je->date,
            ])->all();
    }

    private function topOverdueArCustomers(): array
    {
        if (! Schema::hasTable('invoices')) return [];
        return DB::table('invoices')
            ->whereIn('status', ['unpaid', 'partial'])
            ->where('due_date', '<', now()->toDateString())
            ->select('customer_id', DB::raw('SUM(balance) as total_overdue'))
            ->groupBy('customer_id')
            ->orderByDesc('total_overdue')->limit(5)->get()
            ->map(fn ($r) => [
                'customer_id'   => app('hashids')->encode((int) $r->customer_id),
                'total_overdue' => number_format((float) $r->total_overdue, 2, '.', ''),
            ])->all();
    }
}
