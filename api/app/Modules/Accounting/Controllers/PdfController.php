<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Requests\StatementAsOfRequest;
use App\Modules\Accounting\Requests\StatementDateRangeRequest;
use App\Modules\Accounting\Services\PdfService;
use Illuminate\Http\Request;

class PdfController
{
    public function __construct(private readonly PdfService $pdf) {}

    public function bill(Bill $bill)         { return $this->pdf->bill($bill); }
    public function invoice(Invoice $invoice){ return $this->pdf->invoice($invoice); }
    public function journalEntry(JournalEntry $journalEntry) { return $this->pdf->journalEntry($journalEntry); }

    public function trialBalance(StatementDateRangeRequest $request)
    {
        $this->authorizeExport($request);
        [$from, $to] = $request->range();
        return $this->pdf->trialBalance($from, $to);
    }

    public function incomeStatement(StatementDateRangeRequest $request)
    {
        $this->authorizeExport($request);
        [$from, $to] = $request->range();
        return $this->pdf->incomeStatement($from, $to);
    }

    public function balanceSheet(StatementAsOfRequest $request)
    {
        $this->authorizeExport($request);
        $asOf = $request->asOfDate();
        return $this->pdf->balanceSheet($asOf);
    }

    private function authorizeExport(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('accounting.statements.export'), 403, 'You do not have permission to export statements.');
    }
}
