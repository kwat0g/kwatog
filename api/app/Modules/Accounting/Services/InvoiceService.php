<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\SearchOperator;

use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Events\InvoiceFinalized;
use App\Modules\Accounting\Enums\VatClassification;
use App\Modules\Accounting\Models\Collection as InvoiceCollection;
use App\Modules\Accounting\Models\CreditNoteApplication;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InvoiceService
{
    // OGAMI-008 — contra-revenue account debited for Senior/PWD discounts.

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly JournalEntryService $journals,
        private readonly AccountingPeriodService $periods,
        private readonly TaxPolicyService $taxPolicy,
        private readonly AccountingAccountPolicyService $accounts,
        private readonly PostingAccountResolver $postingAccounts,
        private readonly OfficialReceiptService $receipts,
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
                $qq->where('invoice_number', SearchOperator::like(), "%{$term}%")
                   ->orWhereHas('customer', fn ($cc) => $cc->where('name', SearchOperator::like(), "%{$term}%"));
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
            'collections.journalEntry:id,entry_number',
            'journalEntry:id,entry_number,date,status,total_debit,total_credit',
            // 2026-08-08 — compact O2C stepper: the upstream SO + delivery.
            'salesOrder:id,so_number',
            'delivery:id,delivery_number',
            // role_id required so User's $with=['role'] eager-load can resolve.
            'creator:id,name,role_id',
        ]);
    }

    /** Create a draft invoice (no JE yet). */
    public function create(array $data, User $by): Invoice
    {
        return DB::transaction(function () use ($data, $by) {
            $lifecycle = (string) ($data['lifecycle_type'] ?? 'standard');
            if (! in_array($lifecycle, ['standard', 'prebill'], true)) {
                throw new BusinessRuleException('Invoice lifecycle must be standard or prebill.');
            }
            if ($lifecycle === 'prebill' && trim((string) ($data['prebill_reason'] ?? '')) === '') {
                throw new BusinessRuleException('An approved prebill requires a reason.');
            }
            if ($lifecycle === 'prebill' && ! $by->hasPermission('accounting.invoices.prebill_approve')) {
                throw new BusinessRuleException('You are not authorized to approve a prebill invoice.');
            }
            $customerId = HashIdFilter::decode($data['customer_id'], Customer::class);
            if (! $customerId) {
                throw new BusinessRuleException('Invalid customer selected for invoice.');
            }
            $customer = Customer::query()->lockForUpdate()->find($customerId);
            if (! $customer) {
                throw new BusinessRuleException('Selected customer no longer exists.');
            }
            $source = $this->resolveSourceChain($data, $customer);
            $classification = $this->resolveClassification($data);
            $isVatable = $classification === VatClassification::Vatable;
            [$items, $subtotal] = $this->normalizeItems($data['items'] ?? []);
            $discount = $this->normalizeDiscount($data['senior_pwd_discount'] ?? null, $subtotal);
            [$vat, $total] = $this->computeTotals($classification, $subtotal, $discount);

            $invoice = Invoice::create([
                // Number reserved at finalize-time so drafts that get cancelled don't burn numbers.
                'invoice_number' => null,
                'customer_id'    => $customer->id,
                // C-2 — Persist optional SO/Delivery linkage when supplied so
                // finalize() can promote the parent SO to 'invoiced'.
                'sales_order_id' => $source['sales_order_id'],
                'delivery_id'    => $source['delivery_id'],
                'lifecycle_type' => $lifecycle,
                'prebill_approved_by' => $lifecycle === 'prebill' ? $by->id : null,
                'prebill_approved_at' => $lifecycle === 'prebill' ? now() : null,
                'prebill_reason' => $lifecycle === 'prebill' ? trim((string) $data['prebill_reason']) : null,
                'date'           => $data['date'],
                'due_date'       => $data['due_date']
                    ?? Carbon::parse($data['date'])->addDays($customer->payment_terms_days)->toDateString(),
                'is_vatable'     => $isVatable,
                'vat_classification' => $classification,
                'subtotal'       => $subtotal,
                'vat_amount'     => $vat,
                'senior_pwd_discount' => $discount,
                'buyer_tin'      => $data['buyer_tin'] ?? null,
                'atp_number'     => $data['atp_number'] ?? null,
                'serial_range'   => $data['serial_range'] ?? null,
                'is_original'    => array_key_exists('is_original', $data) ? (bool) $data['is_original'] : true,
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
        return DB::transaction(function () use ($invoice, $data) {
            // Re-read the aggregate under lock. A route-bound draft can be
            // stale after a concurrent finalize or cancel.
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            if ($lockedInvoice->status !== InvoiceStatus::Draft) {
                throw new BusinessRuleException('Only draft invoices can be edited.');
            }

            $classification = $this->resolveClassification($data, $lockedInvoice);
            $isVatable = $classification === VatClassification::Vatable;
            [$items, $subtotal] = $this->normalizeItems($data['items'] ?? []);
            $discount = $this->normalizeDiscount(
                $data['senior_pwd_discount'] ?? (string) $lockedInvoice->senior_pwd_discount,
                $subtotal,
            );
            [$vat, $total] = $this->computeTotals($classification, $subtotal, $discount);

            $lockedInvoice->update([
                'date'         => $data['date']     ?? $lockedInvoice->date,
                'due_date'     => $data['due_date'] ?? $lockedInvoice->due_date,
                'is_vatable'   => $isVatable,
                'vat_classification'  => $classification,
                'subtotal'     => $subtotal,
                'vat_amount'   => $vat,
                'senior_pwd_discount' => $discount,
                'buyer_tin'    => $data['buyer_tin']    ?? $lockedInvoice->buyer_tin,
                'atp_number'   => $data['atp_number']   ?? $lockedInvoice->atp_number,
                'serial_range' => $data['serial_range'] ?? $lockedInvoice->serial_range,
                'is_original'  => array_key_exists('is_original', $data) ? (bool) $data['is_original'] : $lockedInvoice->is_original,
                'total_amount' => $total,
                'balance'      => $total, // no payments yet on a draft
                'remarks'      => $data['remarks'] ?? $lockedInvoice->remarks,
            ]);

            InvoiceItem::where('invoice_id', $lockedInvoice->id)->forceDelete();
            foreach ($items as $row) {
                InvoiceItem::create(array_merge($row, ['invoice_id' => $lockedInvoice->id]));
            }
            return $this->show($lockedInvoice->fresh());
        });
    }

    /** Lock the number, build + post the JE, flip status to finalized. */
    public function finalize(Invoice $invoice, User $by): Invoice
    {
        $finalized = DB::transaction(function () use ($invoice, $by) {
            // The caller may be holding a stale draft. Serialize against the
            // persisted invoice and make every validation/calculation use that
            // authoritative row.
            $lockedInvoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());
            if ($lockedInvoice->status !== InvoiceStatus::Draft) {
                throw new BusinessRuleException('Only draft invoices can be finalized.');
            }

            // OGAMI-001 — block finalizing into a closed period.
            $this->periods->assertPostingAllowed($lockedInvoice->date);

            $lockedInvoice->loadMissing(['items', 'customer']);

            // Standard final invoices are delivery-gated. Prebilling is a
            // distinct, explicitly approved lifecycle and never masquerades
            // as a delivered sale.
            if (($lockedInvoice->lifecycle_type ?? 'standard') === 'standard') {
                $source = $this->lockSourceChain($lockedInvoice);
                $delivery = $source['delivery']
                    ? $source['delivery']->load('items')
                    : null;
                if (! $lockedInvoice->sales_order_id || ! $delivery || $delivery->status !== DeliveryStatus::Confirmed) {
                    throw new BusinessRuleException('A standard sales-order invoice requires a confirmed delivered quantity. Use the approved prebill lifecycle for prebilling.');
                }
                $this->assertInvoiceMatchesConfirmedDelivery($lockedInvoice, $delivery);
            }

            $arId        = $this->configuredAccountId($this->accounts->ar());
            $vatOutputId = $this->configuredAccountId($this->accounts->vatOutput());

            $lines = [];
            $lines[] = [
                'account_id' => $arId,
                'debit'      => (string) $lockedInvoice->total_amount,
                'credit'     => '0.00',
                'description'=> "AR — {$lockedInvoice->customer->name}",
            ];
            foreach ($lockedInvoice->items as $item) {
                $this->postingAccounts->assertTypes((int) $item->revenue_account_id, AccountType::Revenue);
                $lines[] = [
                    'account_id' => $item->revenue_account_id,
                    'debit'      => '0.00',
                    'credit'     => (string) $item->total,
                    'description'=> $item->description,
                ];
            }
            // OGAMI-008 — Senior/PWD discount: contra-revenue debit keeps the JE
            // balanced (AR is net of discount while revenue is booked gross).
            if (Money::gt((string) $lockedInvoice->senior_pwd_discount, '0')) {
                $lines[] = [
                    'account_id' => $this->discountAccountId(),
                    'debit'      => (string) $lockedInvoice->senior_pwd_discount,
                    'credit'     => '0.00',
                    'description'=> 'Senior/PWD discount',
                ];
            }
            if ($lockedInvoice->is_vatable && Money::gt((string) $lockedInvoice->vat_amount, '0')) {
                $lines[] = [
                    'account_id' => $vatOutputId,
                    'debit'      => '0.00',
                    'credit'     => (string) $lockedInvoice->vat_amount,
                    'description'=> 'VAT Output',
                ];
            }

            $invoiceNumber = $this->sequences->generate('invoice');

            $je = $this->journals->create([
                'date'           => $lockedInvoice->date->toDateString(),
                'description'    => "Invoice {$invoiceNumber} to {$lockedInvoice->customer->name}",
                'reference_type' => 'invoice',
                'reference_id'   => $lockedInvoice->id,
                'lines'          => $lines,
            ], $by);
            $je = $this->journals->post($je, $by);

            $lockedInvoice->update([
                'invoice_number'   => $invoiceNumber,
                'journal_entry_id' => $je->id,
                'status'           => InvoiceStatus::Finalized,
            ]);

            // C-2 — Promote the parent SO to 'invoiced' once we have a posted
            // JE and a locked invoice number. No-op if the invoice isn't
            // linked to an SO or the SO is already at/past invoiced.
            if (($lockedInvoice->lifecycle_type ?? 'standard') === 'standard' && $lockedInvoice->sales_order_id) {
                app(\App\Modules\CRM\Services\SalesOrderService::class)
                    ->markInvoiced((int) $lockedInvoice->sales_order_id);
            }

            // 2026-08-08 — final P2P-analog link: broadcast the chain step so
            // the invoice page updates in real time (draft → finalized).
            app(ChainBroadcaster::class)->broadcastFor(
                $lockedInvoice->fresh(),
                InvoiceStatus::Finalized->value,
                auth()->user(),
            );

            return $this->show($lockedInvoice->fresh());
        });

        // Customer email is dispatched only after the accounting transaction
        // commits, so a rollback can never send an invoice that does not
        // exist. The queued listener owns the external email boundary.
        event(new InvoiceFinalized($finalized));

        return $finalized;
    }

    public function cancel(Invoice $invoice, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $by) {
            // Lock the parent before locking its JE so cancel follows the same
            // parent-first order as collection/finalization and never acts on
            // stale amount/status/journal-link attributes.
            $lockedInvoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());
            if (! Money::isZero((string) $lockedInvoice->amount_paid)) {
                throw new BusinessRuleException('Cannot cancel an invoice that has collections.');
            }
            if ($lockedInvoice->status === InvoiceStatus::Cancelled) {
                return $lockedInvoice;
            }

            if ($lockedInvoice->journal_entry_id) {
                $lockedInvoice->loadMissing('journalEntry');
                $je = $lockedInvoice->journalEntry;
                if ($je && $je->status === JournalEntryStatus::Posted) {
                    $this->journals->reverse(
                        $je,
                        $by,
                        now(),
                        "Invoice {$lockedInvoice->invoice_number} cancellation",
                    );
                }
            }
            $lockedInvoice->update([
                'status'       => InvoiceStatus::Cancelled,
                'balance'      => Money::zero(),
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
            ]);
            return $lockedInvoice->fresh();
        });
    }

    public function recordCollection(Invoice $invoice, array $data, User $by): InvoiceCollection
    {
        $amount = Money::round2((string) $data['amount']);

        return DB::transaction(function () use ($invoice, $data, $amount, $by) {
            // Lock and reload before checking status/balance. The caller's
            // invoice can be stale after another collection settled or changed
            // the outstanding balance.
            $lockedInvoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());
            if (Money::lte($amount, '0')) {
                throw new BusinessRuleException('Amount must be > 0.');
            }

            $cashAccountId = $this->postingAccounts->idForTypes($data['cash_account_id'], AccountType::Asset);

            $idempotencyKey = isset($data['idempotency_key'])
                ? trim((string) $data['idempotency_key'])
                : null;
            if ($idempotencyKey === '') {
                $idempotencyKey = null;
            }
            if ($idempotencyKey !== null) {
                $existing = InvoiceCollection::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ((int) $existing->invoice_id !== (int) $lockedInvoice->id) {
                        throw new BusinessRuleException('This idempotency key is already used for another invoice.');
                    }
                    if (Money::cmp((string) $existing->amount, $amount) !== 0
                        || (int) $existing->cash_account_id !== $cashAccountId
                        || $existing->collection_date->toDateString() !== (string) $data['collection_date']
                        || $existing->payment_method?->value !== (string) $data['payment_method']) {
                        throw new BusinessRuleException('The idempotency key was already used with a different collection payload.');
                    }

                    return $existing->fresh(['cashAccount', 'journalEntry', 'officialReceipt']);
                }
            }

            if (in_array($lockedInvoice->status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled, InvoiceStatus::Paid], true)) {
                throw new BusinessRuleException("Cannot record a collection while invoice status is {$lockedInvoice->status->value}.");
            }
            if (Money::gt($amount, (string) $lockedInvoice->balance)) {
                throw new BusinessRuleException("Amount {$amount} exceeds outstanding balance " . $lockedInvoice->balance . '.');
            }

            $coll = InvoiceCollection::create([
                'invoice_id'       => $lockedInvoice->id,
                'cash_account_id'  => $cashAccountId,
                'collection_date'  => $data['collection_date'],
                'amount'           => $amount,
                'payment_method'   => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'idempotency_key'  => $idempotencyKey,
                'created_by'       => $by->id,
            ]);

            $arId = $this->configuredAccountId($this->accounts->ar());
            $je = $this->journals->create([
                'date'           => $coll->collection_date->toDateString(),
                'description'    => "Collection for Invoice {$lockedInvoice->invoice_number}",
                'reference_type' => 'collection',
                'reference_id'   => $coll->id,
                'lines'          => [
                    ['account_id' => $cashAccountId, 'debit' => $amount, 'credit' => '0.00', 'description' => 'Cash received'],
                    ['account_id' => $arId,          'debit' => '0.00',  'credit' => $amount, 'description' => 'AR settled'],
                ],
            ], $by);
            $je = $this->journals->post($je, $by);
            $coll->update(['journal_entry_id' => $je->id]);
            $this->receipts->issueForCollection($coll->fresh(['invoice']), $by);

            $newPaid    = Money::add((string) $lockedInvoice->amount_paid, $amount);
            $newBalance = Money::sub((string) $lockedInvoice->total_amount, $newPaid);
            $newStatus  = Money::isZero($newBalance) ? InvoiceStatus::Paid : InvoiceStatus::Partial;

            $lockedInvoice->update([
                'amount_paid' => $newPaid,
                'balance'     => $newBalance,
                'status'      => $newStatus,
            ]);

            // 2026-08-08 — broadcast the chain step: partial → paid on settle.
            app(ChainBroadcaster::class)->broadcastFor(
                $lockedInvoice->fresh(),
                $newStatus->value,
                auth()->user(),
            );

            return $coll->fresh(['cashAccount', 'journalEntry', 'officialReceipt']);
        });
    }

    public function aging(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();
        $cutoff = $asOf->copy()->endOfDay();
        $rows = Invoice::query()
            // withTrashed: `customers` soft-deletes, `invoices` does not, and
            // `invoices.customer_id` is NOT NULL behind a RESTRICT FK. Under the
            // default scope an archived customer resolved to null and the
            // `$inv->customer->hash_id` read below raised "Attempt to read
            // property on null" — a 500 on the AR aging report AND on the
            // finance dashboard, which calls aging() on every load. The
            // receivable is still owed, so the row belongs in the report.
            ->with(['customer' => static fn ($q) => $q->withTrashed()->select(['id', 'name'])])
            ->whereDate('date', '<=', $asOf->toDateString())
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial, InvoiceStatus::Paid])
                    ->orWhere(function ($cancelled) use ($cutoff): void {
                        $cancelled
                            ->where('status', InvoiceStatus::Cancelled)
                            ->whereNotNull('cancelled_at')
                            ->where('cancelled_at', '>', $cutoff);
                    });
            })
            ->orderBy('customer_id')
            ->get();

        $invoiceIds = $rows->modelKeys();
        $collectionRows = InvoiceCollection::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->get(['invoice_id', 'amount', 'collection_date']);
        $collectionHistory = $collectionRows->groupBy('invoice_id');
        $collected = $collectionHistory
            ->map(fn ($items): string => Money::add(
                ...$items
                    ->filter(fn ($item): bool => $item->collection_date->lte($cutoff))
                    ->pluck('amount')
                    ->all(),
            ));
        $credited = CreditNoteApplication::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('created_at', '<=', $cutoff)
            ->get(['invoice_id', 'amount'])
            ->groupBy('invoice_id')
            ->map(static fn ($items): string => Money::add(...$items->pluck('amount')->all()));

        $buckets = ['current' => '0.00', 'd1_30' => '0.00', 'd31_60' => '0.00', 'd61_90' => '0.00', 'd91_plus' => '0.00', 'total' => '0.00'];
        $byCustomer = [];

        foreach ($rows as $inv) {
            $settled = Money::add(
                (string) ($collected[$inv->id] ?? Money::zero()),
                (string) ($credited[$inv->id] ?? Money::zero()),
            );
            if (Money::isZero($settled)
                && ! isset($collectionHistory[$inv->id])
                && in_array($inv->status, [InvoiceStatus::Partial, InvoiceStatus::Paid], true)
                && $inv->updated_at?->lte($cutoff)) {
                // Legacy/manual fixtures may have amount_paid without child
                // collection rows. Use that snapshot only when it existed by
                // the cutoff; once event rows exist, the event history wins.
                $settled = (string) $inv->amount_paid;
            }
            $balance = Money::clampMin(Money::sub((string) $inv->total_amount, $settled), Money::zero());
            if (Money::isZero($balance)) {
                continue;
            }

            $bucket = $this->agingBucketAt($inv, $asOf);
            $buckets[$bucket] = Money::add($buckets[$bucket], $balance);
            $buckets['total'] = Money::add($buckets['total'], $balance);

            $cid = $inv->customer_id;
            if (! isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer_id'   => $inv->customer?->hash_id,
                    'customer_name' => $inv->customer?->name ?? '(deleted customer)',
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

        return [
            'as_of' => $asOf->toDateString(),
            'buckets' => $buckets,
            'by_customer' => array_values($byCustomer),
        ];
    }

    private function agingBucketAt(Invoice $invoice, Carbon $asOf): string
    {
        if (! $invoice->due_date || $invoice->due_date->toDateString() >= $asOf->toDateString()) {
            return 'current';
        }

        $days = $invoice->due_date->diffInDays($asOf->copy()->startOfDay(), true);

        return match (true) {
            $days <= 30 => 'd1_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd91_plus',
        };
    }

    /**
     * BusinessRuleException rather than ValidationException even though these
     * read like field errors: create() is also the delivery→invoice handoff
     * path, and both DeliveryService::retryInvoiceHandoff() and
     * CreateDraftInvoiceOnDeliveryInvoiceRequested split on
     * `DeliveryInvoiceHandoffException|BusinessRuleException` — "expected,
     * degrade to manual" — versus everything else — "infrastructure fault,
     * rethrow so the queue retries".
     *
     * Note which way this moved. As bare RuntimeExceptions these three ESCAPED
     * that split and were handled as infrastructure faults; naming them
     * BusinessRuleException does not preserve the graceful arm, it puts them in
     * it for the first time. A delivery line with no revenue account or a
     * non-positive quantity now leaves the delivery at
     * `invoice_handoff_status = manual_required` instead of poisoning the queue
     * — which is the right outcome, because no retry can fix either condition.
     * ValidationException would have undone that by escaping the split again.
     *
     * @return array{0: array<int, array{revenue_account_id:int, source_delivery_item_id:?int, description:string, quantity:string, unit:?string, unit_price:string, total:string}>, 1: string}
     */
    private function normalizeItems(array $rawItems): array
    {
        if (count($rawItems) === 0) {
            throw new BusinessRuleException('An invoice must have at least one line item.');
        }
        $rows = []; $subtotal = Money::zero();
        foreach ($rawItems as $raw) {
            $accountId = $this->postingAccounts->idForTypes(
                $raw['revenue_account_id'] ?? null,
                AccountType::Revenue,
            );
            $qty   = Money::round2((string) $raw['quantity']);
            $price = Money::round2((string) $raw['unit_price']);
            $total = Money::round2(bcmul($qty, $price, 4));
            if (Money::lte($qty, '0') || Money::lt($price, '0')) {
                throw new BusinessRuleException('Quantity must be > 0, unit price must be ≥ 0.');
            }
            $rows[] = [
                'revenue_account_id' => $accountId,
                'source_delivery_item_id' => isset($raw['source_delivery_item_id'])
                    ? (is_int($raw['source_delivery_item_id'])
                        ? $raw['source_delivery_item_id']
                        : HashIdFilter::decode($raw['source_delivery_item_id'], \App\Modules\SupplyChain\Models\DeliveryItem::class))
                    : null,
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

    private function assertInvoiceMatchesConfirmedDelivery(
        Invoice $invoice,
        \App\Modules\SupplyChain\Models\Delivery $delivery,
    ): void {
        if ((int) $delivery->sales_order_id !== (int) $invoice->sales_order_id) {
            throw new BusinessRuleException('Invoice delivery does not belong to the selected sales order.');
        }

        $invoice->loadMissing('items');
        $deliveryLines = $delivery->items->keyBy('id');
        $seen = [];
        foreach ($invoice->items as $line) {
            if (! $line->source_delivery_item_id || isset($seen[$line->source_delivery_item_id])) {
                throw new BusinessRuleException('Every standard invoice line requires one unique confirmed delivery line.');
            }
            $source = $deliveryLines->get($line->source_delivery_item_id);
            if (! $source
                || bccomp((string) $line->quantity, (string) $source->quantity, 2) !== 0
                || bccomp((string) $line->unit_price, (string) $source->unit_price, 2) !== 0) {
                throw new BusinessRuleException('Standard invoice quantity and price must match the confirmed delivery line.');
            }
            $seen[$line->source_delivery_item_id] = true;
        }
        if (count($seen) !== $deliveryLines->count()) {
            throw new BusinessRuleException('A standard invoice must include every confirmed delivery line exactly once.');
        }
    }

    /**
     * Resolve a configured control account. The expected classification comes
     * from {@see AccountingAccountPolicyService::typeFor()} so this service does
     * not keep its own copy of the AR/VAT/discount type rules.
     */
    private function configuredAccountId(string $code): int
    {
        return $this->accounts->controlAccountId($code);
    }

    /**
     * Resolve and lock the invoice's source chain before persisting it. The
     * customer is authoritative on the invoice; source records may not be
     * combined across customers or across sales orders.
     *
     * @return array{sales_order_id:?int, delivery_id:?int}
     */
    private function resolveSourceChain(array $data, Customer $customer): array
    {
        $salesOrderId = $this->decodeSourceId($data['sales_order_id'] ?? null, SalesOrder::class);
        $deliveryId = $this->decodeSourceId($data['delivery_id'] ?? null, Delivery::class);
        if ($deliveryId !== null && $salesOrderId === null) {
            throw new BusinessRuleException('A delivery invoice must identify its sales order.');
        }

        if ($salesOrderId !== null) {
            $salesOrder = SalesOrder::query()->lockForUpdate()->find($salesOrderId);
            if (! $salesOrder) {
                throw new BusinessRuleException('Selected sales order no longer exists.');
            }
            if ((int) $salesOrder->customer_id !== (int) $customer->id) {
                throw new BusinessRuleException('Invoice customer must match the selected sales order customer.');
            }
        }

        if ($deliveryId !== null) {
            $delivery = Delivery::query()->lockForUpdate()->find($deliveryId);
            if (! $delivery || (int) $delivery->sales_order_id !== $salesOrderId) {
                throw new BusinessRuleException('Selected delivery does not belong to the selected sales order.');
            }
        }

        return ['sales_order_id' => $salesOrderId, 'delivery_id' => $deliveryId];
    }

    /**
     * Re-lock the persisted source chain during finalization so a stale draft
     * cannot be finalized after its customer/order relationship changes.
     *
     * @return array{sales_order:?SalesOrder, delivery:?Delivery}
     */
    private function lockSourceChain(Invoice $invoice): array
    {
        $salesOrder = null;
        $delivery = null;
        if ($invoice->sales_order_id) {
            $salesOrder = SalesOrder::query()->lockForUpdate()->find($invoice->sales_order_id);
            if (! $salesOrder || (int) $salesOrder->customer_id !== (int) $invoice->customer_id) {
                throw new BusinessRuleException('Invoice customer no longer matches its sales order.');
            }
        }
        if ($invoice->delivery_id) {
            $delivery = Delivery::query()->lockForUpdate()->find($invoice->delivery_id);
            if (! $delivery || (int) $delivery->sales_order_id !== (int) $invoice->sales_order_id) {
                throw new BusinessRuleException('Invoice delivery no longer belongs to its sales order.');
            }
        }

        return ['sales_order' => $salesOrder, 'delivery' => $delivery];
    }

    private function decodeSourceId(mixed $value, string $modelClass): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = is_numeric($value) ? (int) $value : HashIdFilter::decode((string) $value, $modelClass);
        if (! $id) {
            throw new BusinessRuleException('Invalid source document selected for invoice.');
        }
        return $id;
    }

    /**
     * OGAMI-008 — Resolve the VAT classification from request data, honoring an
     * explicit `vat_classification` when present, otherwise falling back to the
     * legacy `is_vatable` boolean (delivery → invoice path) and finally the
     * existing invoice (on update). Default is 'vatable' to preserve behavior.
     */
    private function resolveClassification(array $data, ?Invoice $existing = null): VatClassification
    {
        if (! empty($data['vat_classification'])) {
            return $data['vat_classification'] instanceof VatClassification
                ? $data['vat_classification']
                : VatClassification::from((string) $data['vat_classification']);
        }
        if (array_key_exists('is_vatable', $data)) {
            return $data['is_vatable'] ? VatClassification::Vatable : VatClassification::VatExempt;
        }
        if ($existing) {
            return $existing->vat_classification
                ?? ($existing->is_vatable ? VatClassification::Vatable : VatClassification::VatExempt);
        }
        return VatClassification::Vatable;
    }

    /**
     * Clamp the Senior/PWD discount to [0, subtotal].
     *
     * ValidationException here (not BusinessRuleException): the discount is one
     * input on one form, and the only caller that can trip these bounds is the
     * HTTP invoice form — the delivery→invoice handoff never sends
     * `senior_pwd_discount`, so this cannot reach a chain listener's catch arm.
     */
    private function normalizeDiscount(string|float|int|null $raw, string $subtotal): string
    {
        if ($raw === null || $raw === '') {
            return Money::zero();
        }
        $discount = Money::round2((string) $raw);
        if (Money::lt($discount, '0')) {
            throw ValidationException::withMessages([
                'senior_pwd_discount' => ['Senior/PWD discount must be ≥ 0.'],
            ]);
        }
        if (Money::gt($discount, $subtotal)) {
            throw ValidationException::withMessages([
                'senior_pwd_discount' => ['Senior/PWD discount cannot exceed the subtotal.'],
            ]);
        }
        return $discount;
    }

    /**
     * OGAMI-008 — Compute [vat_amount, total_amount] from the classification.
     * VAT is charged only for 'vatable'; the Senior/PWD discount reduces the
     * VATable base before the 12% is applied and is deducted from the total.
     *
     * @return array{0: string, 1: string}
     */
    private function computeTotals(VatClassification $classification, string $subtotal, string $discount): array
    {
        $netBase = Money::sub($subtotal, $discount);
        $vat = $classification->chargesVat() ? Money::mul($netBase, $this->taxPolicy->requiredVatRate()) : Money::zero();
        $total = Money::add($netBase, $vat);
        return [$vat, $total];
    }

    /** OGAMI-008 — account debited for the Senior/PWD discount contra-revenue line. */
    private function discountAccountId(): int
    {
        return $this->configuredAccountId($this->accounts->discount());
    }
}
