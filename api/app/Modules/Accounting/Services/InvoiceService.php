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
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Events\InvoiceFinalized;
use App\Modules\Accounting\Enums\VatClassification;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Collection as InvoiceCollection;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
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
            $customer = Customer::findOrFail(
                HashIdFilter::decode($data['customer_id'], Customer::class),
            );
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
                'sales_order_id' => isset($data['sales_order_id'])
                    ? HashIdFilter::decode($data['sales_order_id'], \App\Modules\CRM\Models\SalesOrder::class)
                    : null,
                'delivery_id'    => isset($data['delivery_id'])
                    ? HashIdFilter::decode($data['delivery_id'], \App\Modules\SupplyChain\Models\Delivery::class)
                    : null,
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
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new BusinessRuleException('Only draft invoices can be edited.');
        }

        return DB::transaction(function () use ($invoice, $data) {
            $classification = $this->resolveClassification($data, $invoice);
            $isVatable = $classification === VatClassification::Vatable;
            [$items, $subtotal] = $this->normalizeItems($data['items'] ?? []);
            $discount = $this->normalizeDiscount(
                $data['senior_pwd_discount'] ?? (string) $invoice->senior_pwd_discount,
                $subtotal,
            );
            [$vat, $total] = $this->computeTotals($classification, $subtotal, $discount);

            $invoice->update([
                'date'         => $data['date']     ?? $invoice->date,
                'due_date'     => $data['due_date'] ?? $invoice->due_date,
                'is_vatable'   => $isVatable,
                'vat_classification'  => $classification,
                'subtotal'     => $subtotal,
                'vat_amount'   => $vat,
                'senior_pwd_discount' => $discount,
                'buyer_tin'    => $data['buyer_tin']    ?? $invoice->buyer_tin,
                'atp_number'   => $data['atp_number']   ?? $invoice->atp_number,
                'serial_range' => $data['serial_range'] ?? $invoice->serial_range,
                'is_original'  => array_key_exists('is_original', $data) ? (bool) $data['is_original'] : $invoice->is_original,
                'total_amount' => $total,
                'balance'      => $total, // no payments yet on a draft
                'remarks'      => $data['remarks'] ?? $invoice->remarks,
            ]);

            InvoiceItem::where('invoice_id', $invoice->id)->forceDelete();
            foreach ($items as $row) {
                InvoiceItem::create(array_merge($row, ['invoice_id' => $invoice->id]));
            }
            return $this->show($invoice->fresh());
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
                $delivery = $lockedInvoice->delivery_id
                    ? \App\Modules\SupplyChain\Models\Delivery::query()->with('items')->find($lockedInvoice->delivery_id)
                    : null;
                if (! $lockedInvoice->sales_order_id || ! $delivery || $delivery->status !== \App\Modules\SupplyChain\Enums\DeliveryStatus::Confirmed) {
                    throw new BusinessRuleException('A standard sales-order invoice requires a confirmed delivered quantity. Use the approved prebill lifecycle for prebilling.');
                }
                $this->assertInvoiceMatchesConfirmedDelivery($lockedInvoice, $delivery);
            }

            $arId        = $this->accountId($this->accounts->ar());
            $vatOutputId = $this->accountId($this->accounts->vatOutput());

            $lines = [];
            $lines[] = [
                'account_id' => $arId,
                'debit'      => (string) $lockedInvoice->total_amount,
                'credit'     => '0.00',
                'description'=> "AR — {$lockedInvoice->customer->name}",
            ];
            foreach ($lockedInvoice->items as $item) {
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
                    $this->journals->reverse($je, $by);
                }
            }
            $lockedInvoice->update([
                'status'  => InvoiceStatus::Cancelled,
                'balance' => Money::zero(),
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
            if (in_array($lockedInvoice->status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled, InvoiceStatus::Paid], true)) {
                throw new BusinessRuleException("Cannot record a collection while invoice status is {$lockedInvoice->status->value}.");
            }
            if (Money::lte($amount, '0')) {
                throw new BusinessRuleException('Amount must be > 0.');
            }
            if (Money::gt($amount, (string) $lockedInvoice->balance)) {
                throw new BusinessRuleException("Amount {$amount} exceeds outstanding balance " . $lockedInvoice->balance . '.');
            }

            $cashAccountId = HashIdFilter::decode($data['cash_account_id'], Account::class);
            if (! $cashAccountId) {
                throw new BusinessRuleException('Invalid cash account.');
            }

            $coll = InvoiceCollection::create([
                'invoice_id'       => $lockedInvoice->id,
                'cash_account_id'  => $cashAccountId,
                'collection_date'  => $data['collection_date'],
                'amount'           => $amount,
                'payment_method'   => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'created_by'       => $by->id,
            ]);

            $arId = $this->accountId($this->accounts->ar());
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
     * BusinessRuleException rather than ValidationException even though these
     * read like field errors: create() is also the delivery→invoice handoff
     * path, and both DeliveryService::retryInvoiceHandoff() and
     * CreateDraftInvoiceOnDeliveryInvoiceRequested treat
     * `DeliveryInvoiceHandoffException|BusinessRuleException` as "expected,
     * degrade to manual" and everything else as "infrastructure fault,
     * rethrow so the queue retries". A ValidationException here would send a
     * malformed delivery line down the retry path forever.
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
            $accountId = HashIdFilter::decode($raw['revenue_account_id'] ?? null, Account::class);
            if (! $accountId) {
                throw new BusinessRuleException('Invalid revenue account selected on invoice item.');
            }
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

    private function accountId(string $code): int
    {
        $id = Account::query()->where('code', $code)->value('id');
        if (! $id) {
            // Deliberately NOT a BusinessRuleException: $code comes from the
            // accounting settings / COA seed, never from the request. A user
            // told "AR account 1200 not found" can do nothing about it, and
            // dressing a broken chart of accounts as a 422 would hide a real
            // deployment fault behind a form error.
            throw new RuntimeException("Required account {$code} not found in COA.");
        }
        return (int) $id;
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
        return $this->accountId($this->accounts->discount());
    }
}
