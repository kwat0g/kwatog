<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FinanceDashboardService
{
    public function __construct(
        private readonly BillService $billService,
        private readonly InvoiceService $invoiceService,
    ) {}

    public function summary(): array
    {
        return Cache::tags(['financial_statements', 'finance_dashboard'])
            ->remember('finance_dashboard:summary', now()->addSeconds(30), function () {
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
                ];
            });
    }
}
