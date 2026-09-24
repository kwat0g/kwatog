<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Events\CreditNoteFinalized;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\CreditNoteStatus;
use App\Modules\Accounting\Enums\CreditNoteType;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\CreditNoteApplication;
use App\Modules\Accounting\Models\CreditNoteLine;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * REC-13 — AR/AP credit notes.
 *
 * A customer credit note reverses revenue + VAT-output and reduces AR; a
 * supplier credit note reduces AP + reverses expense/inventory + VAT-input.
 * Finalizing posts a balanced, VAT-reversing journal entry (so the subledger
 * and GL stay reconciled — unlike the old RMA negative-invoice hack that never
 * touched the GL). A finalized credit can then be APPLIED against open
 * invoices/bills, reducing both balances.
 */
class CreditNoteService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly JournalEntryService $journals,
        private readonly AccountingPeriodService $periods,
        private readonly TaxPolicyService $taxPolicy,
        private readonly AccountingAccountPolicyService $accounts,
        private readonly PostingAccountResolver $postingAccounts,
    ) {}

    /**
     * Paginated list with optional type/status/party filters.
     *
     * @param array<string, mixed> $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $q = CreditNote::query()->with(['customer:id,name', 'vendor:id,name', 'invoice:id,invoice_number', 'bill:id,bill_number']);

        if (! empty($filters['type'])) {
            $q->where('type', $filters['type']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['customer_id'])) {
            // No (string) cast: HashIdFilter::decode takes mixed, and casting an
            // array payload (?customer_id[]=x) raised an E_WARNING that Laravel
            // rethrows as ErrorException — a 500 on a bad query string.
            $cid = HashIdFilter::decode($filters['customer_id'], \App\Modules\Accounting\Models\Customer::class);
            $q->where('customer_id', $cid ?? 0);
        }
        if (! empty($filters['vendor_id'])) {
            $vid = HashIdFilter::decode($filters['vendor_id'], \App\Modules\Accounting\Models\Vendor::class);
            $q->where('vendor_id', $vid ?? 0);
        }

        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(CreditNote $cn): CreditNote
    {
        return $cn->load([
            'customer:id,name', 'vendor:id,name',
            'invoice:id,invoice_number', 'bill:id,bill_number',
            'lines', 'applications.invoice:id,invoice_number', 'applications.bill:id,bill_number',
        ]);
    }

    /**
     * Create a DRAFT credit note.
     *
     * @param array{
     *   type: string, date: string, is_vatable?: bool, reason?: string,
     *   customer_id?: mixed, vendor_id?: mixed, invoice_id?: mixed, bill_id?: mixed,
     *   return_request_id?: int,
     *   lines: array<int, array{account_id: mixed, description: string, amount: string|float}>
     * } $data
     */
    public function create(array $data, User $by): CreditNote
    {
        $type = CreditNoteType::from($data['type']);
        $lines = $data['lines'] ?? [];
        $customerId = $this->decode($data['customer_id'] ?? null, \App\Modules\Accounting\Models\Customer::class);
        $vendorId = $this->decode($data['vendor_id'] ?? null, \App\Modules\Accounting\Models\Vendor::class);
        $invoiceId = $this->decode($data['invoice_id'] ?? null, Invoice::class);
        $billId = $this->decode($data['bill_id'] ?? null, Bill::class);
        if (count($lines) < 1) {
            throw new BusinessRuleException('A credit note needs at least one line.');
        }

        return DB::transaction(function () use ($data, $type, $lines, $by, $customerId, $vendorId, $invoiceId, $billId) {
            $sourceInvoice = $invoiceId !== null
                ? Invoice::query()->lockForUpdate()->findOrFail($invoiceId)
                : null;
            $sourceBill = $billId !== null
                ? Bill::query()->lockForUpdate()->findOrFail($billId)
                : null;
            $this->assertSourceParty($type, $customerId, $vendorId, $sourceInvoice, $sourceBill);
            $this->assertSourceState($sourceInvoice, $sourceBill);
            $isVatable = $sourceInvoice !== null
                ? (bool) $sourceInvoice->is_vatable
                : ($sourceBill !== null
                    ? (bool) $sourceBill->is_vatable
                    : (bool) ($data['is_vatable'] ?? $this->taxPolicy->isVatRegistered()));

            $subtotal = Money::zero();
            $resolvedLines = [];
            foreach ($lines as $l) {
                $accountType = $type === CreditNoteType::Customer ? AccountType::Revenue : AccountType::Expense;
                $accountId = $this->postingAccounts->idForTypes($l['account_id'] ?? null, $accountType);
                $amount = Money::round2((string) $l['amount']);
                if (Money::lte($amount, '0')) {
                    throw new BusinessRuleException('Credit-note line amount must be > 0.');
                }
                $subtotal = Money::add($subtotal, $amount);
                $resolvedLines[] = ['account_id' => $accountId, 'description' => $l['description'] ?? '', 'amount' => $amount];
            }

            $vat = $isVatable ? Money::round2(Money::mul($subtotal, $this->taxPolicy->requiredVatRate())) : Money::zero();
            $total = Money::add($subtotal, $vat);

            $cn = new CreditNote();
            $cn->fill([
                'type'              => $type->value,
                'customer_id'       => $customerId,
                'vendor_id'         => $vendorId,
                'invoice_id'        => $invoiceId,
                'bill_id'           => $billId,
                'return_request_id' => $data['return_request_id'] ?? null,
                'date'              => $data['date'],
                'is_vatable'        => $isVatable,
                'subtotal'          => $subtotal,
                'vat_amount'        => $vat,
                'total_amount'      => $total,
                'applied_amount'    => '0.00',
                'balance'           => $total,
                'reason'            => $data['reason'] ?? null,
                'created_by'        => $by->id,
            ]);
            $cn->status = CreditNoteStatus::Draft;
            $cn->save();

            foreach ($resolvedLines as $rl) {
                CreditNoteLine::create(['credit_note_id' => $cn->id] + $rl);
            }

            $this->assertParty($cn);

            return $cn->fresh(['lines']);
        });
    }

    /**
     * Finalize a draft credit note: assign a number and post the VAT-reversing
     * journal entry. Idempotent guard on status.
     */
    public function finalize(CreditNote $cn, User $by): CreditNote
    {
        if ($cn->status !== CreditNoteStatus::Draft) {
            throw new BusinessRuleException('Only draft credit notes can be finalized.');
        }

        $finalized = DB::transaction(function () use ($cn, $by) {
            // Lock-then-guard: re-read the authoritative row under a row lock so
            // a concurrent finalizer holding a stale model cannot post the
            // VAT-reversing journal entry twice (P01-01 shape on the GL).
            $locked = CreditNote::query()->lockForUpdate()->findOrFail($cn->getKey());
            if ($locked->status !== CreditNoteStatus::Draft) {
                throw new BusinessRuleException('Only draft credit notes can be finalized.');
            }

            $this->periods->assertPostingAllowed($locked->date->toDateString());
            $locked->loadMissing('lines');
            $this->assertSourceState(
                $locked->invoice_id ? Invoice::query()->lockForUpdate()->find($locked->invoice_id) : null,
                $locked->bill_id ? Bill::query()->lockForUpdate()->find($locked->bill_id) : null,
            );

            $lines = $this->buildGlLines($locked);
            $number = $this->sequences->generate('credit_note', $locked->date);

            $je = $this->journals->create([
                'date'           => $locked->date->toDateString(),
                'description'    => "Credit note {$number} ({$locked->type->label()})",
                'reference_type' => 'credit_note',
                'reference_id'   => $locked->id,
                'lines'          => $lines,
            ], $by);
            $je = $this->journals->post($je, $by);

            $locked->fill(['credit_note_number' => $number, 'journal_entry_id' => $je->id]);
            $locked->status = CreditNoteStatus::Finalized;
            $locked->save();

            return $locked->fresh(['lines']);
        });

        event(new CreditNoteFinalized($finalized));

        return $finalized;
    }

    /**
     * Apply part (or all) of a finalized credit note against an open invoice
     * (customer credit) or bill (supplier credit): reduces both balances,
     * records the application, and advances status. No GL entry — the AR/AP
     * movement was already booked at finalize; application is a subledger offset
     * between the credit and the specific open document.
     */
    public function apply(CreditNote $cn, array $data, User $by): CreditNoteApplication
    {
        $amount = Money::round2((string) $data['amount']);
        if (Money::lte($amount, '0')) {
            throw new BusinessRuleException('Application amount must be > 0.');
        }

        return DB::transaction(function () use ($cn, $data, $amount, $by) {
            // Route-bound credit notes can be stale when two applications race.
            // Lock the authoritative credit row before checking status/balance
            // so an already-consumed note cannot be applied a second time.
            $creditNote = CreditNote::query()->lockForUpdate()->findOrFail($cn->id);
            if ($creditNote->status !== CreditNoteStatus::Finalized) {
                throw new BusinessRuleException('Only a finalized credit note can be applied.');
            }
            if (Money::gt($amount, (string) $creditNote->balance)) {
                throw new BusinessRuleException("Amount {$amount} exceeds the credit note's remaining balance {$creditNote->balance}.");
            }

            $application = new CreditNoteApplication([
                'credit_note_id' => $creditNote->id,
                'amount'         => $amount,
                'created_by'     => $by->id,
            ]);

            if ($creditNote->type === CreditNoteType::Customer) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail(
                    $this->decode($data['invoice_id'] ?? null, Invoice::class)
                        ?? throw new BusinessRuleException('invoice_id is required to apply a customer credit.')
                );
                if ($invoice->customer_id !== $creditNote->customer_id) {
                    throw new BusinessRuleException('Credit note and invoice belong to different customers.');
                }
                if (! in_array($invoice->status, [InvoiceStatus::Finalized, InvoiceStatus::Partial], true)) {
                    throw new BusinessRuleException('A credit note can only be applied to a finalized or partially paid invoice.');
                }
                if (! $invoice->journal_entry_id || ! JournalEntry::query()
                    ->whereKey($invoice->journal_entry_id)
                    ->where('status', JournalEntryStatus::Posted)
                    ->exists()) {
                    throw new BusinessRuleException('The target invoice does not have a posted journal entry.');
                }
                if (Money::gt($amount, (string) $invoice->balance)) {
                    throw new BusinessRuleException("Amount {$amount} exceeds the invoice's outstanding balance {$invoice->balance}.");
                }
                $newPaid    = Money::add((string) $invoice->amount_paid, $amount);
                $newBalance = Money::sub((string) $invoice->total_amount, $newPaid);
                $newStatus  = Money::isZero($newBalance) ? InvoiceStatus::Paid : InvoiceStatus::Partial;
                $invoice->update([
                    'amount_paid' => $newPaid,
                    'balance'     => $newBalance,
                    'status'      => $newStatus,
                ]);
                if ($newStatus === InvoiceStatus::Paid && $invoice->sales_order_id) {
                    app(\App\Modules\CRM\Services\SalesOrderService::class)
                        ->synchronizeCompletionState((int) $invoice->sales_order_id);
                }
                if ($invoice->wasChanged('status')) {
                    $fresh = $invoice->fresh();
                    app(ChainBroadcaster::class)
                        ->broadcastFor($fresh, $newStatus->value, $by);
                }
                $application->invoice_id = $invoice->id;
            } else {
                $bill = Bill::query()->lockForUpdate()->findOrFail(
                    $this->decode($data['bill_id'] ?? null, Bill::class)
                        ?? throw new BusinessRuleException('bill_id is required to apply a supplier credit.')
                );
                if ($bill->vendor_id !== $creditNote->vendor_id) {
                    throw new BusinessRuleException('Credit note and bill belong to different vendors.');
                }
                if (! in_array($bill->status, [BillStatus::Unpaid, BillStatus::Partial], true)) {
                    throw new BusinessRuleException('A credit note can only be applied to an unpaid or partially paid bill.');
                }
                if (! $bill->journal_entry_id || ! JournalEntry::query()
                    ->whereKey($bill->journal_entry_id)
                    ->where('status', JournalEntryStatus::Posted)
                    ->exists()) {
                    throw new BusinessRuleException('The target bill does not have a posted journal entry.');
                }
                if (Money::gt($amount, (string) $bill->balance)) {
                    throw new BusinessRuleException("Amount {$amount} exceeds the bill's outstanding balance {$bill->balance}.");
                }
                $newPaid    = Money::add((string) $bill->amount_paid, $amount);
                $newBalance = Money::sub((string) $bill->total_amount, $newPaid);
                $newStatus  = Money::isZero($newBalance) ? BillStatus::Paid : BillStatus::Partial;
                $bill->update([
                    'amount_paid' => $newPaid,
                    'balance'     => $newBalance,
                    'status'      => $newStatus,
                ]);
                if ($bill->wasChanged('status')) {
                    $fresh = $bill->fresh();
                    app(ChainBroadcaster::class)
                        ->broadcastFor($fresh, $newStatus->value, $by);
                }
                $application->bill_id = $bill->id;
            }

            $application->save();

            $newApplied = Money::add((string) $creditNote->applied_amount, $amount);
            $newCnBalance = Money::sub((string) $creditNote->total_amount, $newApplied);
            $creditNote->fill(['applied_amount' => $newApplied, 'balance' => $newCnBalance]);
            if (Money::isZero($newCnBalance)) {
                $creditNote->status = CreditNoteStatus::Applied;
            }
            $creditNote->save();

            return $application->fresh();
        });
    }

    /**
     * Void an unapplied finalized credit note by reversing its posted JE.
     * Applied credits are intentionally immutable: their applications must be
     * unwound as a separate controlled operation before the note can be voided.
     */
    public function void(CreditNote $cn, User $by, string $reason): CreditNote
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessRuleException('A reason is required to void a credit note.');
        }

        return DB::transaction(function () use ($cn, $by, $reason): CreditNote {
            $locked = CreditNote::query()->lockForUpdate()->findOrFail($cn->getKey());
            if ($locked->status !== CreditNoteStatus::Finalized) {
                throw new BusinessRuleException(
                    'Only an unapplied finalized credit note can be voided; an applied credit note must be unwound before it can be voided.',
                );
            }
            if (! Money::isZero((string) $locked->applied_amount)) {
                throw new BusinessRuleException('An applied credit note must be unwound before it can be voided.');
            }
            if (! $locked->journal_entry_id) {
                throw new BusinessRuleException('The credit note has no posted journal entry to reverse.');
            }

            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($locked->journal_entry_id);
            if ($journal->status !== JournalEntryStatus::Posted) {
                throw new BusinessRuleException('The credit note journal entry is no longer posted.');
            }

            $reversal = $this->journals->reverse($journal, $by, $locked->date, $reason);
            $locked->forceFill([
                'status' => CreditNoteStatus::Void,
                'balance' => '0.00',
                'voided_by' => $by->id,
                'void_reversal_journal_entry_id' => $reversal->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            return $locked->fresh(['lines', 'voidReversalJournalEntry']);
        });
    }

    /**
     * Build the VAT-reversing GL lines. Customer credit reverses the invoice
     * booking (DR revenue, DR VAT-output, CR AR); supplier credit reverses the
     * bill booking (DR AP, CR expense, CR VAT-input).
     *
     * @return array<int, array{account_id: int, debit: string, credit: string, description: string}>
     */
    private function buildGlLines(CreditNote $cn): array
    {
        $lines = [];
        if ($cn->type === CreditNoteType::Customer) {
            // Reverse revenue: DR each revenue account for its line amount.
            foreach ($cn->lines as $l) {
                $lines[] = [
                    'account_id' => $this->postingAccounts->assertTypes((int) $l->account_id, AccountType::Revenue),
                    'debit' => (string) $l->amount,
                    'credit' => '0.00',
                    'description' => $l->description,
                ];
            }
            if ($cn->is_vatable && Money::gt((string) $cn->vat_amount, '0')) {
                $lines[] = ['account_id' => $this->accountId($this->accounts->vatOutput()), 'debit' => (string) $cn->vat_amount, 'credit' => '0.00', 'description' => 'VAT Output reversal'];
            }
            $lines[] = ['account_id' => $this->accountId($this->accounts->ar()), 'debit' => '0.00', 'credit' => (string) $cn->total_amount, 'description' => 'AR reduction'];
        } else {
            // Supplier credit: DR AP, CR each expense account, CR VAT input.
            $lines[] = ['account_id' => $this->accountId($this->accounts->ap()), 'debit' => (string) $cn->total_amount, 'credit' => '0.00', 'description' => 'AP reduction'];
            foreach ($cn->lines as $l) {
                $lines[] = [
                    'account_id' => $this->postingAccounts->assertTypes((int) $l->account_id, AccountType::Expense),
                    'debit' => '0.00',
                    'credit' => (string) $l->amount,
                    'description' => $l->description,
                ];
            }
            if ($cn->is_vatable && Money::gt((string) $cn->vat_amount, '0')) {
                $lines[] = ['account_id' => $this->accountId($this->accounts->vatInput()), 'debit' => '0.00', 'credit' => (string) $cn->vat_amount, 'description' => 'VAT Input reversal'];
            }
        }
        return $lines;
    }

    /**
     * The header must name one party, and a linked source document must belong
     * to that same party. This is enforced at draft creation so a mismatched
     * source cannot appear in the credit-note list or detail response.
     */
    private function assertParty(CreditNote $cn): void
    {
        if ($cn->type === CreditNoteType::Customer && ! $cn->customer_id) {
            throw new BusinessRuleException('A customer credit note requires a customer.');
        }
        if ($cn->type === CreditNoteType::Supplier && ! $cn->vendor_id) {
            throw new BusinessRuleException('A supplier credit note requires a vendor.');
        }
    }

    /**
     * A credit note against a source document reverses that document's GL
     * booking, so the source must actually have a posted one and must not
     * already have been reversed. Draft sources were never booked; cancelled
     * sources were already unwound. Referencing either posts a credit JE
     * against nothing and desynchronises the subledger from the GL.
     */
    private function assertSourceState(?Invoice $invoice, ?Bill $bill): void
    {
        if ($invoice !== null && ! in_array($invoice->status, [
            InvoiceStatus::Finalized,
            InvoiceStatus::Partial,
            InvoiceStatus::Paid,
        ], true)) {
            throw new BusinessRuleException('A credit note can only reference a finalized, partially paid, or paid invoice.');
        }
        if ($bill !== null && ! in_array($bill->status, [
            BillStatus::Unpaid,
            BillStatus::Partial,
            BillStatus::Paid,
        ], true)) {
            throw new BusinessRuleException('A credit note can only reference an unpaid, partially paid, or paid bill.');
        }

        $journalEntryId = $invoice?->journal_entry_id ?? $bill?->journal_entry_id;
        if ($journalEntryId !== null && ! JournalEntry::query()
            ->whereKey($journalEntryId)
            ->where('status', JournalEntryStatus::Posted)
            ->exists()) {
            throw new BusinessRuleException('The credit note source does not have a posted journal entry.');
        }
    }

    private function assertSourceParty(
        CreditNoteType $type,
        ?int $customerId,
        ?int $vendorId,
        ?Invoice $sourceInvoice,
        ?Bill $sourceBill,
    ): void {
        if ($type === CreditNoteType::Customer && $sourceBill !== null) {
            throw new BusinessRuleException('A customer credit note must reference an invoice, not a bill.');
        }
        if ($type === CreditNoteType::Supplier && $sourceInvoice !== null) {
            throw new BusinessRuleException('A supplier credit note must reference a bill, not an invoice.');
        }
        if ($sourceInvoice !== null && (int) $sourceInvoice->customer_id !== (int) $customerId) {
            throw new BusinessRuleException('Credit note and source invoice belong to different customers.');
        }
        if ($sourceBill !== null && (int) $sourceBill->vendor_id !== (int) $vendorId) {
            throw new BusinessRuleException('Credit note and source bill belong to different vendors.');
        }
    }

    private function decode(mixed $value, string $modelClass): ?int
    {
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? (int) $value : HashIdFilter::decode($value, $modelClass);
    }

    private function accountId(string $code): int
    {
        return $this->accounts->controlAccountId($code);
    }
}
