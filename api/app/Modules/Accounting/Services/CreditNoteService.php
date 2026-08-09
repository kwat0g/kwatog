<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\CreditNoteStatus;
use App\Modules\Accounting\Enums\CreditNoteType;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\CreditNoteApplication;
use App\Modules\Accounting\Models\CreditNoteLine;
use App\Modules\Accounting\Models\Invoice;
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
        if (count($lines) < 1) {
            throw new BusinessRuleException('A credit note needs at least one line.');
        }

        return DB::transaction(function () use ($data, $type, $lines, $by) {
            $isVatable = (bool) ($data['is_vatable'] ?? $this->taxPolicy->isVatRegistered());

            $subtotal = Money::zero();
            $resolvedLines = [];
            foreach ($lines as $l) {
                $accountId = is_numeric($l['account_id'] ?? null)
                    ? (int) $l['account_id']
                    : HashIdFilter::decode((string) ($l['account_id'] ?? ''), Account::class);
                if (! $accountId) {
                    throw new BusinessRuleException('Invalid account on a credit-note line.');
                }
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
                'customer_id'       => $this->decode($data['customer_id'] ?? null, \App\Modules\Accounting\Models\Customer::class),
                'vendor_id'         => $this->decode($data['vendor_id'] ?? null, \App\Modules\Accounting\Models\Vendor::class),
                'invoice_id'        => $this->decode($data['invoice_id'] ?? null, Invoice::class),
                'bill_id'           => $this->decode($data['bill_id'] ?? null, Bill::class),
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

        return DB::transaction(function () use ($cn, $by) {
            $this->periods->assertPostingAllowed($cn->date->toDateString());
            $cn->loadMissing('lines');

            $lines = $this->buildGlLines($cn);
            $number = $this->sequences->generate('credit_note');

            $je = $this->journals->create([
                'date'           => $cn->date->toDateString(),
                'description'    => "Credit note {$number} ({$cn->type->label()})",
                'reference_type' => 'credit_note',
                'reference_id'   => $cn->id,
                'lines'          => $lines,
            ], $by);
            $je = $this->journals->post($je, $by);

            $cn->fill(['credit_note_number' => $number, 'journal_entry_id' => $je->id]);
            $cn->status = CreditNoteStatus::Finalized;
            $cn->save();

            return $cn->fresh(['lines']);
        });
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
        if ($cn->status !== CreditNoteStatus::Finalized) {
            throw new BusinessRuleException('Only a finalized credit note can be applied.');
        }
        $amount = Money::round2((string) $data['amount']);
        if (Money::lte($amount, '0')) {
            throw new BusinessRuleException('Application amount must be > 0.');
        }
        if (Money::gt($amount, (string) $cn->balance)) {
            throw new BusinessRuleException("Amount {$amount} exceeds the credit note's remaining balance {$cn->balance}.");
        }

        return DB::transaction(function () use ($cn, $data, $amount, $by) {
            $application = new CreditNoteApplication([
                'credit_note_id' => $cn->id,
                'amount'         => $amount,
                'created_by'     => $by->id,
            ]);

            if ($cn->type === CreditNoteType::Customer) {
                $invoice = Invoice::query()->findOrFail(
                    $this->decode($data['invoice_id'] ?? null, Invoice::class)
                        ?? throw new BusinessRuleException('invoice_id is required to apply a customer credit.')
                );
                if ($invoice->customer_id !== $cn->customer_id) {
                    throw new BusinessRuleException('Credit note and invoice belong to different customers.');
                }
                if (Money::gt($amount, (string) $invoice->balance)) {
                    throw new BusinessRuleException("Amount {$amount} exceeds the invoice's outstanding balance {$invoice->balance}.");
                }
                $newPaid    = Money::add((string) $invoice->amount_paid, $amount);
                $newBalance = Money::sub((string) $invoice->total_amount, $newPaid);
                $invoice->update([
                    'amount_paid' => $newPaid,
                    'balance'     => $newBalance,
                    'status'      => Money::isZero($newBalance) ? InvoiceStatus::Paid : InvoiceStatus::Partial,
                ]);
                $application->invoice_id = $invoice->id;
            } else {
                $bill = Bill::query()->findOrFail(
                    $this->decode($data['bill_id'] ?? null, Bill::class)
                        ?? throw new BusinessRuleException('bill_id is required to apply a supplier credit.')
                );
                if ($bill->vendor_id !== $cn->vendor_id) {
                    throw new BusinessRuleException('Credit note and bill belong to different vendors.');
                }
                if (Money::gt($amount, (string) $bill->balance)) {
                    throw new BusinessRuleException("Amount {$amount} exceeds the bill's outstanding balance {$bill->balance}.");
                }
                $newPaid    = Money::add((string) $bill->amount_paid, $amount);
                $newBalance = Money::sub((string) $bill->total_amount, $newPaid);
                $bill->update([
                    'amount_paid' => $newPaid,
                    'balance'     => $newBalance,
                    'status'      => Money::isZero($newBalance) ? BillStatus::Paid : BillStatus::Partial,
                ]);
                $application->bill_id = $bill->id;
            }

            $application->save();

            $newApplied = Money::add((string) $cn->applied_amount, $amount);
            $newCnBalance = Money::sub((string) $cn->total_amount, $newApplied);
            $cn->fill(['applied_amount' => $newApplied, 'balance' => $newCnBalance]);
            if (Money::isZero($newCnBalance)) {
                $cn->status = CreditNoteStatus::Applied;
            }
            $cn->save();

            // 2026-08-08 — final P2P link: a supplier credit settling a bill
            // advances the bill's chain step just like a cash payment does.
            if (isset($bill) && $bill->wasChanged('status')) {
                $fresh = $bill->fresh();
                DB::afterCommit(function () use ($fresh): void {
                    app(ChainBroadcaster::class)
                        ->broadcastFor($fresh, (string) $fresh->status?->value);
                });
            }

            return $application->fresh();
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
                $lines[] = ['account_id' => (int) $l->account_id, 'debit' => (string) $l->amount, 'credit' => '0.00', 'description' => $l->description];
            }
            if ($cn->is_vatable && Money::gt((string) $cn->vat_amount, '0')) {
                $lines[] = ['account_id' => $this->accountId($this->accounts->vatOutput()), 'debit' => (string) $cn->vat_amount, 'credit' => '0.00', 'description' => 'VAT Output reversal'];
            }
            $lines[] = ['account_id' => $this->accountId($this->accounts->ar()), 'debit' => '0.00', 'credit' => (string) $cn->total_amount, 'description' => 'AR reduction'];
        } else {
            // Supplier credit: DR AP, CR each expense account, CR VAT input.
            $lines[] = ['account_id' => $this->accountId($this->accounts->ap()), 'debit' => (string) $cn->total_amount, 'credit' => '0.00', 'description' => 'AP reduction'];
            foreach ($cn->lines as $l) {
                $lines[] = ['account_id' => (int) $l->account_id, 'debit' => '0.00', 'credit' => (string) $l->amount, 'description' => $l->description];
            }
            if ($cn->is_vatable && Money::gt((string) $cn->vat_amount, '0')) {
                $lines[] = ['account_id' => $this->accountId($this->accounts->vatInput()), 'debit' => '0.00', 'credit' => (string) $cn->vat_amount, 'description' => 'VAT Input reversal'];
            }
        }
        return $lines;
    }

    private function assertParty(CreditNote $cn): void
    {
        if ($cn->type === CreditNoteType::Customer && ! $cn->customer_id) {
            throw new BusinessRuleException('A customer credit note requires a customer.');
        }
        if ($cn->type === CreditNoteType::Supplier && ! $cn->vendor_id) {
            throw new BusinessRuleException('A supplier credit note requires a vendor.');
        }
    }

    private function decode(mixed $value, string $modelClass): ?int
    {
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? (int) $value : HashIdFilter::decode($value, $modelClass);
    }

    private function accountId(string $code): int
    {
        $id = Account::query()->where('code', $code)->value('id');
        if (! $id) {
            throw new BusinessRuleException("Required account {$code} not found in COA.");
        }
        return (int) $id;
    }
}
