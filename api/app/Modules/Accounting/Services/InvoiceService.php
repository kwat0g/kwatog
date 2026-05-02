<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Collection as InvoiceCollection;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InvoiceService
{
    private const VAT_RATE   = '0.12';
    private const AR_CODE    = '1100';
    private const VAT_OUTPUT = '2060';

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly JournalEntryService $journals,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Invoice::query()->with(['customer:id,name']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['customer_id'])) {
            $cid = HashIdFilter::decode($filters['customer_id'], Customer::class);
            if ($cid) $q->where('customer_id', $cid);
        }
        if (! empty($filters['from'])) $q->whereDate('date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('date', '<=', $filters['to']);
        if (! empty($filters['overdue'])) {
            $q->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial])
              ->whereDate('due_date', '<', now());
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('invoice_number', 'ilike', "%{$term}%")
                   ->orWhereHas('customer', fn ($cc) => $cc->where('name', 'ilike', "%{$term}%"));
            });
        }

        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Invoice $invoice): Invoice
    {
        return $invoice->load([
            'customer',
            'items.revenueAccount:id,code,name',
            'collections.cashAccount:id,code,name',
            'journalEntry:id,entry_number,date,status',
            // role_id required so User's $with=['role'] eager-load can resolve.
            'creator:id,name,role_id',
        ]);
    }

    /** Create a draft invoice (no JE yet). */
    public function create(array $data, User $by): Invoice
    {
        return DB::transaction(function () use ($data, $by) {
            $customer = Customer::findOrFail(
                HashIdFilter::decode($data['customer_id'], Customer::class),
            );
            $isVatable = (bool) ($data['is_vatable'] ?? true);
            [$items, $subtotal] = $this->normalizeItems($data['items'] ?? []);
            $vat = $isVatable ? Money::mul($subtotal, self::VAT_RATE) : Money::zero();
            $total = Money::add($subtotal, $vat);

            $invoice = Invoice::create([
                // Number reserved at finalize-time so drafts that get cancelled don't burn numbers.
                'invoice_number' => 'DRAFT-' . substr(bin2hex(random_bytes(4)), 0, 8),
                'customer_id'    => $customer->id,
                'date'           => $data['date'],
                'due_date'       => $data['due_date']
                    ?? Carbon::parse($data['date'])->addDays($customer->payment_terms_days)->toDateString(),
                'is_vatable'     => $isVatable,
                'subtotal'       => $subtotal,
                'vat_amount'     => $vat,
                'total_amount'   => $total,
                'amount_paid'    => Money::zero(),
                'balance'        => $total,
                'status'         => InvoiceStatus::Draft,
                'created_by'     => $by->id,
                'remarks'        => $data['remarks'] ?? null,
            ]);

            foreach ($items as $row) {
                InvoiceItem::create(array_merge($row, ['invoice_id' => $invoice->id]));
            }

            return $this->show($invoice->fresh());
        });
    }

    public function update(Invoice $invoice, array $data, User $by): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new RuntimeException('Only draft invoices can be edited.');
        }

        return DB::transaction(function () use ($invoice, $data) {
            $isVatable = (bool) ($data['is_vatable'] ?? $invoice->is_vatable);
            [$items, $subtotal] = $this->normalizeItems($data['items'] ?? []);
            $vat = $isVatable ? Money::mul($subtotal, self::VAT_RATE) : Money::zero();
            $total = Money::add($subtotal, $vat);

            $invoice->update([
                'date'         => $data['date']     ?? $invoice->date,
                'due_date'     => $data['due_date'] ?? $invoice->due_date,
                'is_vatable'   => $isVatable,
                'subtotal'     => $subtotal,
                'vat_amount'   => $vat,
                'total_amount' => $total,
                'balance'      => $total, // no payments yet on a draft
                'remarks'      => $data['remarks'] ?? $invoice->remarks,
            ]);

            InvoiceItem::where('invoice_id', $invoice->id)->delete();
            foreach ($items as $row) {
                InvoiceItem::create(array_merge($row, ['invoice_id' => $invoice->id]));
            }
            return $this->show($invoice->fresh());
        });
    }

    /** Lock the number, build + post the JE, flip status to finalized. */
    public function finalize(Invoice $invoice, User $by): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new RuntimeException('Only draft invoices can be finalized.');
        }

        return DB::transaction(function () use ($invoice, $by) {
            $invoice->loadMissing(['items', 'customer']);

            $arId        = $this->accountId(self::AR_CODE);
            $vatOutputId = $this->accountId(self::VAT_OUTPUT);

            $lines = [];
            $lines[] = [
                'account_id' => $arId,
                'debit'      => (string) $invoice->total_amount,
                'credit'     => '0.00',
                'description'=> "AR — {$invoice->customer->name}",
            ];
            foreach ($invoice->items as $item) {
                $lines[] = [
                    'account_id' => $item->revenue_account_id,
                    'debit'      => '0.00',
                    'credit'     => (string) $item->total,
                    'description'=> $item->description,
                ];
            }
            if ($invoice->is_vatable && Money::gt((string) $invoice->vat_amount, '0')) {
                $lines[] = [
                    'account_id' => $vatOutputId,
                    'debit'      => '0.00',
                    'credit'     => (string) $invoice->vat_amount,
                    'description'=> 'VAT Output',
                ];
            }

            $invoiceNumber = $this->sequences->generate('invoice');

            $je = $this->journals->create([
                'date'           => $invoice->date->toDateString(),
                'description'    => "Invoice {$invoiceNumber} to {$invoice->customer->name}",
                'reference_type' => 'invoice',
                'reference_id'   => $invoice->id,
                'lines'          => $lines,
            ], $by);
            $je = $this->journals->post($je, $by);

            $invoice->update([
                'invoice_number'   => $invoiceNumber,
                'journal_entry_id' => $je->id,
                'status'           => InvoiceStatus::Finalized,
            ]);

            return $this->show($invoice->fresh());
        });
    }

    public function cancel(Invoice $invoice, User $by): Invoice
    {
        if (! Money::isZero((string) $invoice->amount_paid)) {
            throw new RuntimeException('Cannot cancel an invoice that has collections.');
        }
        if ($invoice->status === InvoiceStatus::Cancelled) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice, $by) {
            if ($invoice->journal_entry_id) {
                $je = $invoice->journalEntry;
                if ($je && $je->status === JournalEntryStatus::Posted) {
                    $this->journals->reverse($je, $by);
                }
            }
            $invoice->update([
                'status'  => InvoiceStatus::Cancelled,
                'balance' => Money::zero(),
            ]);
            return $invoice->fresh();
        });
    }

    public function recordCollection(Invoice $invoice, array $data, User $by): InvoiceCollection
    {
        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled, InvoiceStatus::Paid], true)) {
            throw new RuntimeException("Cannot record a collection while invoice status is {$invoice->status->value}.");
        }

        $amount = Money::round2((string) $data['amount']);
        if (Money::lte($amount, '0')) {
            throw new RuntimeException('Amount must be > 0.');
        }
        if (Money::gt($amount, (string) $invoice->balance)) {
            throw new RuntimeException("Amount {$amount} exceeds outstanding balance " . $invoice->balance . '.');
        }

        return DB::transaction(function () use ($invoice, $data, $amount, $by) {
            $cashAccountId = HashIdFilter::decode($data['cash_account_id'], Account::class);
            if (! $cashAccountId) {
                throw new RuntimeException('Invalid cash account.');
            }

            $coll = InvoiceCollection::create([
                'invoice_id'       => $invoice->id,
                'cash_account_id'  => $cashAccountId,
                'collection_date'  => $data['collection_date'],
                'amount'           => $amount,
                'payment_method'   => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'created_by'       => $by->id,
            ]);

            $arId = $this->accountId(self::AR_CODE);
            $je = $this->journals->create([
                'date'           => $coll->collection_date->toDateString(),
                'description'    => "Collection for Invoice {$invoice->invoice_number}",
                'reference_type' => 'collection',
                'reference_id'   => $coll->id,
                'lines'          => [
                    ['account_id' => $cashAccountId, 'debit' => $amount, 'credit' => '0.00', 'description' => 'Cash received'],
                    ['account_id' => $arId,          'debit' => '0.00',  'credit' => $amount, 'description' => 'AR settled'],
                ],
            ], $by);
            $je = $this->journals->post($je, $by);
            $coll->update(['journal_entry_id' => $je->id]);

            $newPaid    = Money::add((string) $invoice->amount_paid, $amount);
            $newBalance = Money::sub((string) $invoice->total_amount, $newPaid);
            $newStatus  = Money::isZero($newBalance) ? InvoiceStatus::Paid : InvoiceStatus::Partial;

            $invoice->update([
                'amount_paid' => $newPaid,
                'balance'     => $newBalance,
                'status'      => $newStatus,
            ]);

            return $coll->fresh(['cashAccount']);
        });
    }

    public function aging(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? now();
        $rows = Invoice::query()
            ->with('customer:id,name')
            ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial])
            ->orderBy('customer_id')
            ->get();

        $buckets = ['current' => '0.00', 'd1_30' => '0.00', 'd31_60' => '0.00', 'd61_90' => '0.00', 'd91_plus' => '0.00', 'total' => '0.00'];
        $byCustomer = [];

        foreach ($rows as $inv) {
            $bucket = $inv->agingBucket($asOf);
            $balance = (string) $inv->balance;
            if (! isset($buckets[$bucket])) continue; // safety
            $buckets[$bucket] = Money::add($buckets[$bucket], $balance);
            $buckets['total'] = Money::add($buckets['total'], $balance);

            $cid = $inv->customer_id;
            if (! isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer_id'   => $inv->customer->hash_id,
                    'customer_name' => $inv->customer->name,
                    'current'       => '0.00',
                    'd1_30'         => '0.00',
                    'd31_60'        => '0.00',
                    'd61_90'        => '0.00',
                    'd91_plus'      => '0.00',
                    'total'         => '0.00',
                ];
            }
            $byCustomer[$cid][$bucket] = Money::add($byCustomer[$cid][$bucket], $balance);
            $byCustomer[$cid]['total'] = Money::add($byCustomer[$cid]['total'], $balance);
        }

        return ['buckets' => $buckets, 'by_customer' => array_values($byCustomer)];
    }

    /**
     * @return array{0: array<int, array{revenue_account_id:int, description:string, quantity:string, unit:?string, unit_price:string, total:string}>, 1: string}
     */
    private function normalizeItems(array $rawItems): array
    {
        if (count($rawItems) === 0) {
            throw new RuntimeException('An invoice must have at least one line item.');
        }
        $rows = []; $subtotal = Money::zero();
        foreach ($rawItems as $raw) {
            $accountId = HashIdFilter::decode($raw['revenue_account_id'] ?? null, Account::class);
            if (! $accountId) {
                throw new RuntimeException('Invalid revenue_account_id on invoice item.');
            }
            $qty   = Money::round2((string) $raw['quantity']);
            $price = Money::round2((string) $raw['unit_price']);
            $total = Money::round2(bcmul($qty, $price, 4));
            if (Money::lte($qty, '0') || Money::lt($price, '0')) {
                throw new RuntimeException('Quantity must be > 0, unit price must be ≥ 0.');
            }
            $rows[] = [
                'revenue_account_id' => $accountId,
                'description'        => (string) $raw['description'],
                'quantity'           => $qty,
                'unit'               => $raw['unit'] ?? null,
                'unit_price'         => $price,
                'total'              => $total,
            ];
            $subtotal = Money::add($subtotal, $total);
        }
        return [$rows, $subtotal];
    }

    private function accountId(string $code): int
    {
        $id = Account::query()->where('code', $code)->value('id');
        if (! $id) {
            throw new RuntimeException("Required account {$code} not found in COA.");
        }
        return (int) $id;
    }
}
