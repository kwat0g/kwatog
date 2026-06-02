<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Payroll\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class FinanceDashboardService
{
    public function __construct(
        private readonly BillService $billService,
        private readonly InvoiceService $invoiceService,
    ) {}

    public function summary(): array
    {
        // Task D5 — bumped cache key (`v2`) so existing cached payloads from
        // before this revision aren't served with the new schema missing.
        return Cache::tags(['financial_statements', 'finance_dashboard'])
            ->remember('finance_dashboard:summary:v2', now()->addSeconds(30), function () {
                // Cash balance: sum of asset/cash accounts (1010 + 1020 + 1030).
                $cashBalance = (string) DB::table('journal_entry_lines as jel')
                    ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                    ->join('accounts as a',         'a.id',  '=', 'jel.account_id')
                    ->where('je.status', 'posted')
                    ->whereIn('a.code', ['1010', '1020', '1030'])
                    ->selectRaw('COALESCE(SUM(jel.debit) - SUM(jel.credit), 0) as bal')
                    ->value('bal');

                $arOutstanding = (string) Invoice::query()
                    ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial])
                    ->sum('balance');
                $apOutstanding = (string) Bill::query()
                    ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
                    ->sum('balance');

                $monthStart = now()->startOfMonth()->toDateString();
                $monthEnd   = now()->endOfMonth()->toDateString();
                $revenueMtd = (string) DB::table('journal_entry_lines as jel')
                    ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                    ->join('accounts as a',         'a.id',  '=', 'jel.account_id')
                    ->where('je.status', 'posted')
                    ->where('a.type', 'revenue')
                    ->whereBetween('je.date', [$monthStart, $monthEnd])
                    ->selectRaw('COALESCE(SUM(jel.credit) - SUM(jel.debit), 0) as rev')
                    ->value('rev');

                $arAging = $this->invoiceService->aging();
                $apAging = $this->billService->aging();

                $recentJournalEntries = JournalEntry::query()
                    ->posted()
                    ->orderByDesc('date')->orderByDesc('id')
                    ->limit(10)
                    ->get(['id', 'entry_number', 'date', 'description', 'total_debit', 'reference_type', 'reference_id'])
                    ->map(fn ($je) => [
                        'id'           => $je->hash_id,
                        'entry_number' => $je->entry_number,
                        'date'         => $je->date->toDateString(),
                        'description'  => $je->description,
                        'total_debit'  => (string) $je->total_debit,
                        'reference'    => $je->referenceLabel(),
                    ]);

                $topOverdue = collect($arAging['by_customer'])
                    ->sortByDesc(fn ($r) => Money::cmp($r['total'], '0'))
                    ->sortByDesc(fn ($r) => (float) $r['total'])
                    ->take(5)
                    ->values()
                    ->all();

                return [
                    'cash_balance'           => Money::round2($cashBalance),
                    'ar_outstanding'         => Money::round2($arOutstanding),
                    'ap_outstanding'         => Money::round2($apOutstanding),
                    'revenue_mtd'            => Money::round2($revenueMtd),
                    'ar_aging_summary'       => $arAging['buckets'],
                    'ap_aging_summary'       => $apAging['buckets'],
                    'recent_journal_entries' => $recentJournalEntries,
                    'top_overdue_customers'  => $topOverdue,
                    // Task D5 — additional Finance Officer panels.
                    'payroll_pipeline'       => $this->payrollPipeline(),
                    'unposted_jes'           => $this->unpostedJes(),
                    'ap_due_this_week'       => $this->apDueThisWeek(),
                    'budget_vs_actual_top'   => $this->budgetVsActualTop(),
                ];
            });
    }

    /**
     * Task D5 — Payroll periods grouped by lifecycle status, scoped to the
     * last 90 days so closed-out periods from a year ago don't dilute the view.
     *
     * @return array{draft:int, processing:int, approved:int, finalized:int, disbursed:int, total:int}
     */
    private function payrollPipeline(): array
    {
        $cutoff = CarbonImmutable::now()->subDays(90)->toDateString();

        $rows = PayrollPeriod::query()
            ->where('period_start', '>=', $cutoff)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $base = ['draft' => 0, 'processing' => 0, 'approved' => 0, 'finalized' => 0, 'disbursed' => 0];
        foreach ($rows as $status => $count) {
            $key = (string) $status;
            if (array_key_exists($key, $base)) $base[$key] = (int) $count;
        }
        $base['total'] = array_sum($base);
        return $base;
    }

    /**
     * Task D5 — Draft (unposted) journal entries as a finance hygiene KPI.
     *
     * @return array{count:int, oldest_date: string|null}
     */
    private function unpostedJes(): array
    {
        $count = (int) JournalEntry::query()->where('status', 'draft')->count();
        $oldest = JournalEntry::query()->where('status', 'draft')
            ->orderBy('date')->value('date');

        return [
            'count'       => $count,
            'oldest_date' => $oldest ? CarbonImmutable::parse((string) $oldest)->toDateString() : null,
        ];
    }

    /**
     * Task D5 — AP bills coming due in the next 7 days, capped at 8 rows.
     *
     * @return array{count:int, total:string, items: array<int, array<string, mixed>>}
     */
    private function apDueThisWeek(): array
    {
        $today  = CarbonImmutable::now()->toDateString();
        $weekly = CarbonImmutable::now()->addDays(7)->toDateString();

        $base = Bill::query()
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->whereBetween('due_date', [$today, $weekly]);

        $count = (int) (clone $base)->count();
        $total = (string) (clone $base)->sum('balance');

        $items = (clone $base)
            ->with('vendor:id,name')
            ->orderBy('due_date')
            ->limit(8)
            ->get(['id', 'bill_number', 'vendor_id', 'due_date', 'balance'])
            ->map(fn ($b) => [
                'id'          => $b->hash_id,
                'bill_number' => $b->bill_number,
                'vendor_name' => $b->vendor?->name ?? '—',
                'due_date'    => $b->due_date?->toDateString(),
                'balance'     => Money::round2((string) $b->balance),
            ])
            ->all();

        return [
            'count' => $count,
            'total' => Money::round2($total),
            'items' => $items,
        ];
    }

    /**
     * Task D5 — Top 5 budget categories by overspend (or highest utilization)
     * for the current fiscal year. Returns null when budgeting is unconfigured
     * (no active fiscal year, no budgets, or service throws) so the SPA can
     * hide the panel cleanly.
     *
     * @return array<int, array{category:string, budget:string, actual:string, variance:string, variance_pct:float}>|null
     */
    private function budgetVsActualTop(): ?array
    {
        try {
            /** @var BudgetService $svc */
            $svc = app(BudgetService::class);
            $fy = $svc->getCurrentFiscalYear();
            if (! $fy) return null;

            $data = $svc->budgetVsActual((int) $fy->id);
            $rows = $this->extractBudgetRows($data);
            if ($rows === []) return null;

            // Sort by overspend first (variance_pct desc), then take top 5.
            usort($rows, fn ($a, $b) => $b['variance_pct'] <=> $a['variance_pct']);
            return array_slice($rows, 0, 5);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Best-effort extraction of normalized {category, budget, actual, ...}
     * rows from BudgetService::budgetVsActual, which historically has been
     * shaped slightly differently across modules. Unknown shapes are skipped.
     *
     * @param  mixed  $data
     * @return array<int, array{category:string, budget:string, actual:string, variance:string, variance_pct:float}>
     */
    private function extractBudgetRows(mixed $data): array
    {
        $candidates = [];
        if (is_array($data)) {
            // Common shapes: ['rows' => [...]], ['data' => [...]], or just a flat list.
            if (isset($data['rows']) && is_array($data['rows'])) {
                $candidates = $data['rows'];
            } elseif (isset($data['data']) && is_array($data['data'])) {
                $candidates = $data['data'];
            } else {
                $candidates = $data;
            }
        }

        $out = [];
        foreach ($candidates as $row) {
            if (! is_array($row)) continue;
            $category = (string) ($row['category'] ?? $row['account_name'] ?? $row['name'] ?? $row['department'] ?? '');
            if ($category === '') continue;
            $budget = (string) ($row['budget'] ?? $row['budgeted'] ?? $row['budget_amount'] ?? '0');
            $actual = (string) ($row['actual'] ?? $row['actual_amount'] ?? '0');
            $variance = (string) ($row['variance'] ?? bcsub($actual, $budget, 2));
            $variancePct = isset($row['variance_pct'])
                ? (float) $row['variance_pct']
                : ((float) $budget > 0 ? ((float) $actual / (float) $budget) * 100 : 0.0);

            $out[] = [
                'category'     => $category,
                'budget'       => Money::round2($budget),
                'actual'       => Money::round2($actual),
                'variance'     => Money::round2($variance),
                'variance_pct' => round($variancePct, 1),
            ];
        }
        return $out;
    }
}
