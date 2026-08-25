<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Enums\DocumentType;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\Statements\BalanceSheetService;
use App\Modules\Accounting\Services\Statements\IncomeStatementService;
use App\Modules\Accounting\Services\Statements\TrialBalanceService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly StatementMoneyFormatter $statementMoney,
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    public function bill(Bill $bill): StreamedResponse
    {
        $bill->load(['vendor', 'items.expenseAccount', 'payments.cashAccount']);
        return $this->storeAndStream($bill, DocumentType::Bill, 'pdf.bill', ['bill' => $bill]);
    }

    public function invoice(Invoice $invoice): StreamedResponse
    {
        $invoice->load(['customer', 'items.revenueAccount', 'collections']);
        return $this->storeAndStream($invoice, DocumentType::Invoice, 'pdf.invoice', ['invoice' => $invoice]);
    }

    public function customerInvoice(Invoice $invoice): StreamedResponse
    {
        $invoice->load(['customer', 'items', 'collections']);
        return $this->storeAndStream($invoice, DocumentType::Invoice, 'pdf.customer-invoice', ['invoice' => $invoice]);
    }

    public function journalEntry(JournalEntry $je): StreamedResponse
    {
        $je->load(['lines.account', 'creator', 'poster']);
        return $this->storeAndStream($je, DocumentType::JournalEntry, 'pdf.journal-entry', ['je' => $je]);
    }

    public function trialBalance(Carbon $from, Carbon $to): Response
    {
        $data = $this->trialBalance->generate($from, $to);
        $bytes = $this->renderer->render('pdf.trial-balance', [
            'data'    => $data,
            'currency' => $this->currency(),
            'money'   => $this->statementMoney,
        ], ['title' => 'Trial Balance']);
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="TrialBalance-'.$from->toDateString().'-'.$to->toDateString().'.pdf"']);
    }

    public function incomeStatement(Carbon $from, Carbon $to): Response
    {
        $data = $this->incomeStatement->generate($from, $to);
        $bytes = $this->renderer->render('pdf.income-statement', [
            'data'    => $data,
            'currency' => $this->currency(),
            'money'   => $this->statementMoney,
        ], ['title' => 'Income Statement']);
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="IncomeStatement-'.$from->toDateString().'-'.$to->toDateString().'.pdf"']);
    }

    public function purchaseOrder(PurchaseOrder $po): StreamedResponse
    {
        $po->load(['vendor', 'items.item']);

        $approvals = collect();
        if (method_exists($po, 'approvalRecords') && $po->relationLoaded('approvalRecords')) {
            $approvals = $po->approvalRecords->map(fn ($r) => [
                'role'      => $r->role?->label ?? $r->role_slug,
                'name'      => $r->approver?->name,
                'signed_at' => optional($r->acted_at)->toDateString(),
            ]);
        }

        return $this->storeAndStream($po, DocumentType::PurchaseOrder, 'pdf.purchase-order', [
            'po' => $po,
            'now' => now(),
            'approvals' => $approvals,
        ]);
    }

    public function balanceSheet(Carbon $asOf): Response
    {
        $data = $this->balanceSheet->generate($asOf);
        $bytes = $this->renderer->render('pdf.balance-sheet', [
            'data'    => $data,
            'currency' => $this->currency(),
            'money'   => $this->statementMoney,
        ], ['title' => 'Balance Sheet']);
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="BalanceSheet-'.$asOf->toDateString().'.pdf"']);
    }

    private function currency(): string
    {
        return strtoupper($this->settings->requiredString('accounting.functional_currency_code'));
    }

    /** @param array<string, mixed> $data */
    private function storeAndStream(\Illuminate\Database\Eloquent\Model $entity, DocumentType $type, string $view, array $data): StreamedResponse
    {
        $bytes = $this->renderer->render($view, $data, ['title' => $type->label()]);
        $actor = auth()->user();
        $user = $actor instanceof User ? $actor : null;
        $document = $this->vault->store($bytes, $type, $entity, $user);

        return $this->vault->streamInline($document);
    }
}
