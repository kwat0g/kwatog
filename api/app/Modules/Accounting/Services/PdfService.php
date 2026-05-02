<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\Statements\BalanceSheetService;
use App\Modules\Accounting\Services\Statements\IncomeStatementService;
use App\Modules\Accounting\Services\Statements\TrialBalanceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Response;

/**
 * Generates PDFs for printable accounting artifacts using DomPDF.
 *
 * Convention: DejaVu Sans is the only font we ship — DomPDF cannot reliably
 * embed Geist; the SPA Geist visuals are NOT replicated in PDF.
 */
class PdfService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TrialBalanceService $trialBalance,
        private readonly IncomeStatementService $incomeStatement,
        private readonly BalanceSheetService $balanceSheet,
    ) {}

    public function bill(Bill $bill): Response
    {
        $bill->load(['vendor', 'items.expenseAccount', 'payments.cashAccount']);
        $pdf = Pdf::loadView('pdf.bill', [
            'bill'    => $bill,
            'company' => $this->company(),
            'user'    => optional(request()->user())->name,
        ])->setPaper('a4');
        return $pdf->stream("Bill-{$bill->bill_number}.pdf");
    }

    public function invoice(Invoice $invoice): Response
    {
        $invoice->load(['customer', 'items.revenueAccount', 'collections']);
        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => $this->company(),
            'user'    => optional(request()->user())->name,
        ])->setPaper('a4');
        return $pdf->stream("Invoice-{$invoice->invoice_number}.pdf");
    }

    public function journalEntry(JournalEntry $je): Response
    {
        $je->load(['lines.account', 'creator', 'poster']);
        $pdf = Pdf::loadView('pdf.journal-entry', [
            'je'      => $je,
            'company' => $this->company(),
            'user'    => optional(request()->user())->name,
        ])->setPaper('a4');
        return $pdf->stream("JE-{$je->entry_number}.pdf");
    }

    public function trialBalance(Carbon $from, Carbon $to): Response
    {
        $data = $this->trialBalance->generate($from, $to);
        $pdf = Pdf::loadView('pdf.trial-balance', [
            'data'    => $data,
            'company' => $this->company(),
            'user'    => optional(request()->user())->name,
        ])->setPaper('a4');
        return $pdf->stream("TrialBalance-{$from->toDateString()}-{$to->toDateString()}.pdf");
    }

    public function incomeStatement(Carbon $from, Carbon $to): Response
    {
        $data = $this->incomeStatement->generate($from, $to);
        $pdf = Pdf::loadView('pdf.income-statement', [
            'data'    => $data,
            'company' => $this->company(),
            'user'    => optional(request()->user())->name,
        ])->setPaper('a4');
        return $pdf->stream("IncomeStatement-{$from->toDateString()}-{$to->toDateString()}.pdf");
    }

    public function balanceSheet(Carbon $asOf): Response
    {
        $data = $this->balanceSheet->generate($asOf);
        $pdf = Pdf::loadView('pdf.balance-sheet', [
            'data'    => $data,
            'company' => $this->company(),
            'user'    => optional(request()->user())->name,
        ])->setPaper('a4');
        return $pdf->stream("BalanceSheet-{$asOf->toDateString()}.pdf");
    }

    /** @return array{name:string, address:string, tin:?string} */
    private function company(): array
    {
        return [
            'name'    => (string) $this->settings->get('company.name', 'Philippine Ogami Corporation'),
            'address' => (string) $this->settings->get('company.address', 'FCIE, Dasmariñas, Cavite, Philippines'),
            'tin'     => $this->settings->get('company.tin'),
        ];
    }
}
