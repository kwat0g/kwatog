<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PdfService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PdfController
{
    public function __construct(private readonly PdfService $pdf) {}

    public function bill(Bill $bill)         { return $this->pdf->bill($bill); }
    public function invoice(Invoice $invoice){ return $this->pdf->invoice($invoice); }
    public function journalEntry(JournalEntry $journalEntry) { return $this->pdf->journalEntry($journalEntry); }

    public function trialBalance(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse((string) $request->query('from')) : now()->startOfMonth();
        $to   = $request->filled('to')   ? Carbon::parse((string) $request->query('to'))   : now()->endOfMonth();
        return $this->pdf->trialBalance($from, $to);
    }

    public function incomeStatement(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse((string) $request->query('from')) : now()->startOfMonth();
        $to   = $request->filled('to')   ? Carbon::parse((string) $request->query('to'))   : now()->endOfMonth();
        return $this->pdf->incomeStatement($from, $to);
    }

    public function balanceSheet(Request $request)
    {
        $asOf = $request->filled('as_of') ? Carbon::parse((string) $request->query('as_of')) : now();
        return $this->pdf->balanceSheet($asOf);
    }
}
