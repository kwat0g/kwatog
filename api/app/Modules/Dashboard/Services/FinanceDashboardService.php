<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Dashboard\Services\ForecastingDashboardService;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\PanelGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class FinanceDashboardService
{
    public function __construct(
        private readonly BillService $billService,
        private readonly InvoiceService $invoiceService,
        private readonly ForecastingDashboardService $forecastingService,
        private readonly SettingsService $settings,
        private readonly PanelGate $gate,
    ) {}

    /**
     * Every gate this payload consults. Listed once so the cache signature and
     * the panel map cannot fall out of step — a gate missing from here would
     * make two different payloads share one cache entry.
     */
    private const GATES = [
        'accounting.invoices.view',
        'accounting.bills.view',
        'accounting.journal.view',
        'payroll.periods.view',
        'budgeting.view',
    ];

    public function summary(User $user): array
    {
        // Task D5 — bumped cache key (`v2`) so existing cached payloads from
        // before this revision aren't served with the new schema missing.
        //
        // The key now also carries the caller's answers to GATES. The old shared
        // key was correct only while every panel was ungated; with gating it
        // would have handed a viewer another viewer's panel set.
        $signature = $this->gate->signature($user, self::GATES);

        return Cache::tags(['financial_statements', 'finance_dashboard'])
            ->remember("finance_dashboard:summary:v2:{$signature}", now()->addSeconds(30), function () use ($user) {
                $cashCodes = array_values(array_filter([
                    $this->settings->get('accounting.accounts.cash_code'),
                    $this->settings->get('accounting.accounts.payroll_cash_code'),
                    $this->settings->get('accounting.accounts.asset_cash_code'),
                ], static fn ($code) => is_string($code) && $code !== ''));
                // Cash balance is derived from the configured cash accounts.
                $cashBalance = (string) DB::table('journal_entry_lines as jel')
                    ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                    ->join('accounts as a',         'a.id',  '=', 'jel.account_id')
                    ->where('je.status', 'posted')
                    ->whereIn('a.code', $cashCodes)
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

                return $this->gate->panels($user, [
                    // Cash and revenue ride the page grant
                    // (`accounting.dashboard.view`): reaching this endpoint at
                    // all already required it.
                    'cash_balance'           => [null,                        fn () => Money::round2($cashBalance)],
                    'revenue_mtd'            => [null,                        fn () => Money::round2($revenueMtd)],
                    'ar_outstanding'         => ['accounting.invoices.view',  fn () => Money::round2($arOutstanding)],
                    'ar_aging_summary'       => ['accounting.invoices.view',  fn () => $arAging['buckets']],
                    // Named customers and what they owe.
                    'top_overdue_customers'  => ['accounting.invoices.view',  fn () => $topOverdue],
                    'ap_outstanding'         => ['accounting.bills.view',     fn () => Money::round2($apOutstanding)],
                    'ap_aging_summary'       => ['accounting.bills.view',     fn () => $apAging['buckets']],
                    'ap_due_this_week'       => ['accounting.bills.view',     fn () => $this->apDueThisWeek()],
                    'recent_journal_entries' => ['accounting.journal.view',   fn () => $recentJournalEntries],
                    'unposted_jes'           => ['accounting.journal.view',   fn () => $this->unpostedJes()],
                    // Payroll run counts are payroll's, not accounting's — the
                    // one panel here whose data comes from another module.
                    'payroll_pipeline'       => ['payroll.periods.view',      fn () => $this->payrollPipeline()],
                    'budget_vs_actual_top'   => ['budgeting.view',            fn () => $this->budgetVsActualTop()],
                    // A projection of revenue, gated like revenue.
                    'revenue_forecast'       => [null,                        fn () => $this->forecastingService->revenueForecast()],
                    // Configured windows, not data.
                    'payroll_pipeline_history_days' => [null,                 fn () => $this->settings->requiredInt('dashboard.finance.payroll_pipeline_history_days', 1)],
                    'ap_due_horizon_days'    => [null,                        fn () => $this->settings->requiredInt('dashboard.widgets.ap_due_horizon_days', 0)],
                ]);
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
        $historyDays = $this->settings->requiredInt('dashboard.finance.payroll_pipeline_history_days', 1);
        $cutoff = CarbonImmutable::now()->subDays($historyDays)->toDateString();

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
        $base['stages'] = collect([
            PayrollPeriodStatus::Draft,
            PayrollPeriodStatus::Processing,
            PayrollPeriodStatus::Approved,
            PayrollPeriodStatus::Finalized,
            PayrollPeriodStatus::Disbursed,
        ])->map(fn (PayrollPeriodStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
            'count' => $base[$status->value] ?? 0,
        ])->all();
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
        $horizonDays = $this->settings->requiredInt('dashboard.widgets.ap_due_horizon_days', 0);
        $weekly = CarbonImmutable::now()->addDays($horizonDays)->toDateString();

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
     * @return array<int, array{category:string, budget:string|null, actual:string|null, variance:string|null, variance_pct:float|null}>|null
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
     * @return array<int, array{category:string, budget:string|null, actual:string|null, variance:string|null, variance_pct:float|null}>
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
            $budgetRaw = $row['budget'] ?? $row['budgeted'] ?? $row['budget_amount'] ?? null;
            $actualRaw = $row['actual'] ?? $row['actual_amount'] ?? null;
            $budget = $budgetRaw !== null && $budgetRaw !== '' ? Money::round2((string) $budgetRaw) : null;
            $actual = $actualRaw !== null && $actualRaw !== '' ? Money::round2((string) $actualRaw) : null;
            $varianceRaw = $row['variance'] ?? null;
            $variance = $varianceRaw !== null && $varianceRaw !== ''
                ? Money::round2((string) $varianceRaw)
                : ($budget !== null && $actual !== null ? Money::round2(bcsub($actual, $budget, 2)) : null);
            $variancePct = array_key_exists('variance_pct', $row) && $row['variance_pct'] !== null
                ? (float) $row['variance_pct']
                : ($budget !== null && $actual !== null && (float) $budget > 0 ? ((float) $actual / (float) $budget) * 100 : null);

            $out[] = [
                'category'     => $category,
                'budget'       => $budget,
                'actual'       => $actual,
                'variance'     => $variance,
                'variance_pct' => $variancePct === null ? null : round($variancePct, 1),
            ];
        }
        return $out;
    }
}
