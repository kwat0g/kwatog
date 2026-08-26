<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\TaxPolicyService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\BillPaymentStatus;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Exceptions\ThreeWayMatchException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\ThreeWayMatchService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BillService
{
    private const THREE_WAY_OVERRIDE_PERMISSION = 'accounting.bills.three_way_override';
    private const PAYMENT_VOID_PERMISSION = 'accounting.bills.void_payment';

    /** AP control account code. */
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly AccountingPeriodService $periods,
        private readonly ThreeWayMatchService $threeWayMatch,
        private readonly BudgetEnforcementService $budget,
        private readonly TaxPolicyService $taxPolicy,
        private readonly AccountingAccountPolicyService $accounts,
        private readonly PostingAccountResolver $postingAccounts,
        private readonly \App\Common\Services\DocumentSequenceService $sequences,
        private readonly \App\Common\Services\SettingsService $settings,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Bill::query()->with(['vendor:id,name']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $vendorId = HashIdFilter::decode($filters['vendor_id'], Vendor::class);
            if ($vendorId) {
                $q->where('vendor_id', $vendorId);
            }
        }
        if (! empty($filters['from'])) {
            $q->whereDate('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('date', '<=', $filters['to']);
        }
        if (! empty($filters['overdue'])) {
            $q->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
                ->whereDate('due_date', '<', now());
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('bill_number', SearchOperator::like(), "%{$term}%")
                    ->orWhereHas('vendor', fn ($vv) => $vv->where('name', SearchOperator::like(), "%{$term}%"));
            });
        }

        return $q->orderByDesc('date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Bill $bill): Bill
    {
        return $bill->load([
            'vendor',
            'items.expenseAccount:id,code,name',
            'payments.cashAccount:id,code,name',
            'payments.journalEntry:id,entry_number,status',
            'payments.voidReversalJournalEntry:id,entry_number,status',
            'payments.voidedBy:id,name,role_id',
            'payments.replacementPayment:id,bill_id,amount,status',
            'journalEntry:id,entry_number,date,status,total_debit,total_credit',
            // role_id required so User's $with=['role'] eager-load can resolve.
            'creator:id,name,role_id',
            'exceptionOwner:id,name,role_id',
            'exceptionApprover:id,name,role_id',
            'threeWayOverrider:id,name,role_id',
            // REC-02 — surface the linked PO so the detail page can render the
            // 3-way-match link row (BillResource exposes purchase_order when loaded).
            // 2026-08-08 — compact P2P stepper: also pull the PR behind the PO.
            // Column list is constrained; purchase_request_id keeps the nested
            // eager load resolvable without selecting the full PO row.
            'purchaseOrder:id,po_number,purchase_request_id',
            'purchaseOrder.purchaseRequest:id,pr_number',
            // 2026-08-08 — source receipt for auto-created draft bills (status
            // feeds the P2P stepper's GRN step).
            'goodsReceiptNote:id,grn_number,status',
        ]);
    }

    /**
     * Create a bill and, by default, build/post its balanced AP journal.
     *
     * The explicit draft entry point below reuses the same normalization and
     * 3-way-match snapshot logic while leaving the ledger untouched until a
     * reviewer calls postDraft().
     */
    public function create(array $data, User $by, bool $postToLedger = true): Bill
    {
        return DB::transaction(function () use ($data, $by, $postToLedger) {
            // OGAMI-001 — block posting/back-dating into a closed period.
            $this->periods->assertPostingAllowed($data['date']);

            $vendor = Vendor::findOrFail(
                HashIdFilter::decode($data['vendor_id'], Vendor::class)
            );
            $provenance = (string) ($data['provenance_type'] ?? 'stock');
            $this->assertBillProvenance($provenance, $data, $by);
            $isVatable = (bool) ($data['is_vatable'] ?? $this->taxPolicy->isVatRegistered());

            // Build items + totals.
            [$items, $subtotal] = $this->normalizeItems($data['items'] ?? []);
            $vat = $isVatable ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate()) : Money::zero();
            $total = Money::add($subtotal, $vat);

            // Vendor uniqueness on bill_number.
            $exists = Bill::query()
                ->where('vendor_id', $vendor->id)
                ->where('bill_number', $data['bill_number'])
                ->exists();
            if ($exists) {
                throw new BusinessRuleException("Bill number '{$data['bill_number']}' already exists for this vendor.");
            }

            // Budget enforcement check.
            if (! empty($data['department_id'])) {
                $deptId = is_int($data['department_id'])
                    ? $data['department_id']
                    : HashIdFilter::decode($data['department_id'], Department::class);
                if ($deptId) {
                    [$canProceed, , $message] = $this->budget->checkAvailability($deptId, (string) $total);
                    if (! $canProceed) {
                        throw ValidationException::withMessages([
                            'budget' => [$message],
                        ]);
                    }
                }
            }

            // Optional PO link + three-way match.
            $poId = null;
            $hasVariances = false;
            $matchSnapshot = null;
            $allowOverride = false;
            $overrideReason = null;
            if (! empty($data['purchase_order_id'])) {
                $poId = HashIdFilter::decode($data['purchase_order_id'], PurchaseOrder::class)
                    ?? (int) $data['purchase_order_id'];

                // OGAMI-006 — never bill against a cancelled/closed PO. A supplier
                // invoice for a PO that was cancelled (or already closed) must not
                // silently post to AP + GL. Guard regardless of 3-way-match config.
                if ($poId) {
                    $poStatus = PurchaseOrder::query()->whereKey($poId)->value('status');
                    $poStatusValue = $poStatus instanceof \BackedEnum ? $poStatus->value : (string) $poStatus;
                    if (in_array($poStatusValue, [
                        PurchaseOrderStatus::Cancelled->value,
                        PurchaseOrderStatus::Closed->value,
                    ], true)) {
                        throw ValidationException::withMessages([
                            'purchase_order_id' => ["Cannot bill against a {$poStatusValue} purchase order."],
                        ]);
                    }
                }

                if ($poId) {
                    $po = PurchaseOrder::query()->with(['items.item'])->findOrFail($poId);
                    [$itemsForMatch] = $this->normalizeItems($data['items'] ?? []);
                    // H-7: Align bill lines to PO lines by item_id FK, not by index.
                    // A skipped or reordered bill line no longer silently corrupts
                    // the variance check. matchForPo() keys results by item_id, so a
                    // missing bill line for a PO line surfaces as billQty=0 / 100%
                    // variance — exactly the right behavior.
                    $billLines = [];
                    foreach ($itemsForMatch as $li) {
                        $billLines[] = [
                            'item_id' => $li['item_id'],
                            'description' => $li['description'],
                            'quantity' => $li['quantity'],
                            'unit_price' => $li['unit_price'],
                        ];
                    }
                    $result = $this->threeWayMatch->matchForPo($po, array_values($billLines));
                    $allowOverride = (bool) ($data['allow_override'] ?? false);
                    if ($result->overallStatus === 'blocked' && $allowOverride) {
                        throw new BusinessRuleException(
                            'A blocking 3-way-match override must be approved by a separate authorized checker while posting a draft bill.'
                        );
                    }
                    if ($result->overallStatus === 'blocked' && $postToLedger) {
                        throw new ThreeWayMatchException('3-way match blocked by variance.', $result->toArray());
                    }
                    $hasVariances = $result->overallStatus !== 'matched';
                    $matchSnapshot = $result->toArray();
                }
            }

            $bill = Bill::create([
                'bill_number' => $data['bill_number'],
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $poId,
                'goods_receipt_note_id' => $provenance === 'stock' ? (HashIdFilter::decode($data['goods_receipt_note_id'], GoodsReceiptNote::class) ?? (int) $data['goods_receipt_note_id']) : null,
                'provenance_type' => $provenance,
                'exception_evidence' => $provenance === 'service' ? trim((string) $data['exception_evidence']) : null,
                'exception_owner_id' => $provenance === 'service' ? $by->id : null,
                'exception_approved_by' => $provenance === 'service' ? $by->id : null,
                'exception_approved_at' => $provenance === 'service' ? now() : null,
                'date' => $data['date'],
                'due_date' => $data['due_date']
                    ?? Carbon::parse($data['date'])->addDays($vendor->payment_terms_days)->toDateString(),
                'is_vatable' => $isVatable,
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total_amount' => $total,
                'amount_paid' => Money::zero(),
                'balance' => $total,
                'has_variances' => $hasVariances,
                'three_way_match_snapshot' => $matchSnapshot,
                // An override is never approved at bill creation. A blocking
                // variance must cross the draft -> checker posting boundary so
                // the maker and authorized checker are distinct actors.
                'three_way_overridden' => false,
                'three_way_overridden_by' => null,
                'three_way_overridden_at' => null,
                'three_way_override_reason' => null,
                'status' => $postToLedger ? BillStatus::Unpaid : BillStatus::Draft,
                'created_by' => $by->id,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($items as $row) {
                BillItem::create(array_merge($row, ['bill_id' => $bill->id]));
            }

            if ($postToLedger) {
                $this->postBillToGl($bill, $vendor, $items, $isVatable, $vat, $total, $by);
            }

            return $this->show($bill->fresh());
        });
    }

    /**
     * Stage an unposted supplier/AP bill for review. Any 3-way-match variance
     * is persisted on the draft instead of blocking the supplier submission;
     * the posting boundary rechecks it and requires an approved override.
     */
    public function createDraft(array $data, User $by): Bill
    {
        return $this->create($data, $by, postToLedger: false);
    }

    /**
     * 2026-08-08 — Auto-bill chain. When a GRN is accepted, the listener calls
     * this to pre-create the supplier bill in DRAFT state. Lines come from the
     * GRN (accepted quantities × unit cost), the default expense account is
     * resolved like the B2B portal's invoice submission, and the due date
     * follows the vendor payment terms. NOTHING is posted — the bill sits in
     * draft until accounting reviews it and calls postDraft().
     */
    public function createDraftForGrn(GoodsReceiptNote $grn, User $by): ?Bill
    {
        return DB::transaction(function () use ($grn, $by) {
            // Serialize duplicate accepted events against the GRN row. The
            // goods_receipt_note_id column is indexed for lookup, but legacy
            // data may not satisfy a unique constraint; the lock keeps the
            // idempotency check and bill insert atomic for this workflow.
            $lockedGrn = GoodsReceiptNote::query()
                ->lockForUpdate()
                ->find($grn->id);
            if (! $lockedGrn) {
                return null;
            }
            if ($lockedGrn->status !== \App\Modules\Inventory\Enums\GrnStatus::Accepted) {
                return null; // only fully-accepted receipts stage a bill
            }
            if (Bill::query()->where('goods_receipt_note_id', $lockedGrn->id)->exists()) {
                return null; // idempotent — one draft per GRN
            }

            $lockedGrn->loadMissing(['vendor', 'purchaseOrder', 'items.item', 'items.purchaseOrderItem']);
            $vendor = $lockedGrn->vendor;
            $po = $lockedGrn->purchaseOrder;
            if (! $vendor || ! $po) {
                return null;
            }
            if ((int) $vendor->id !== (int) $po->vendor_id) {
                throw new BusinessRuleException('The accepted GRN vendor does not match its purchase order vendor.');
            }
            $billDate = $lockedGrn->accepted_at?->toDateString() ?? now()->toDateString();

            $expenseAccountId = $this->defaultExpenseAccountId();
            if (! $expenseAccountId) {
                throw new BusinessRuleException('No default expense account configured. Please contact the administrator.');
            }

            // Vatability follows the source document (the PO), not the
            // company-wide registration flag — a VAT-exempt PO must not
            // spawn a draft bill with VAT, and vice versa.
            $isVatable = (bool) $po->is_vatable;
            $rows = [];
            $subtotal = Money::zero();
            foreach ($lockedGrn->items as $line) {
                $qty = Money::round2((string) $line->quantity_accepted);
                if (Money::lte($qty, '0')) {
                    continue; // rejected lines are not billed
                }
                $unitPrice = Money::round2((string) $line->unit_cost);
                $total = Money::round2(bcmul($qty, $unitPrice, 4));
                $description = $line->item?->name
                    ?? $line->purchaseOrderItem?->description
                    ?? "Line {$line->id}";
                $unit = $line->item?->unit_of_measure ?? $line->purchaseOrderItem?->unit;
                $rows[] = [
                    'expense_account_id' => $expenseAccountId,
                    'item_id' => $line->item_id,
                    'description' => (string) $description,
                    'quantity' => $qty,
                    'unit' => $unit,
                    'unit_price' => $unitPrice,
                    'total' => $total,
                ];
                $subtotal = Money::add($subtotal, $total);
            }
            if ($rows === []) {
                return null; // nothing accepted → nothing to bill
            }

            $po->loadMissing(['items.item']);
            $match = $this->threeWayMatch->matchForPo($po, array_map(
                static fn (array $row): array => [
                    'item_id' => $row['item_id'],
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                ],
                $rows,
            ));
            $hasVariances = $match->overallStatus !== 'matched';
            $matchSnapshot = $match->toArray();
            $reviewNote = $match->overallStatus === 'blocked'
                ? ' 3-way match requires manual review before posting.'
                : '';

            $vat = $isVatable ? Money::mul($subtotal, $this->taxPolicy->requiredVatRate()) : Money::zero();
            $total = Money::add($subtotal, $vat);

            $bill = Bill::create([
                'bill_number' => $this->sequences->generate('bill'),
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $po->id,
                'goods_receipt_note_id' => $lockedGrn->id,
                'provenance_type' => 'stock',
                'date' => $billDate,
                'due_date' => Carbon::parse($billDate)->addDays($vendor->payment_terms_days)->toDateString(),
                'is_vatable' => $isVatable,
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total_amount' => $total,
                'amount_paid' => Money::zero(),
                'balance' => $total,
                'status' => BillStatus::Draft,
                'created_by' => $by->id,
                'has_variances' => $hasVariances,
                'three_way_match_snapshot' => $matchSnapshot,
                'remarks' => "Auto-created from GRN {$lockedGrn->grn_number}. Review and post to record the payable.{$reviewNote}",
            ]);

            foreach ($rows as $row) {
                BillItem::create(array_merge($row, ['bill_id' => $bill->id]));
            }

            return $this->show($bill->fresh());
        });
    }

    /**
     * 2026-08-08 — Post a draft bill: builds + posts the AP/expense JE and
     * flips the bill to Unpaid. The point of the draft state: nothing touches
     * the ledger until a human reviews the auto-created amounts.
     */
    public function postDraft(
        Bill $bill,
        User $by,
        bool $allowOverride = false,
        ?string $overrideReason = null,
    ): Bill {
        $blockedMatch = null;
        $result = DB::transaction(function () use (&$blockedMatch, $bill, $by, $allowOverride, $overrideReason) {
            $lockedBill = Bill::query()
                ->lockForUpdate()
                ->findOrFail($bill->id);
            if ($lockedBill->status !== BillStatus::Draft) {
                throw new BusinessRuleException('Only draft bills can be posted.');
            }
            $this->assertPersistedBillProvenance($lockedBill);

            // Recompute at the point of posting. A GRN, PO, or draft line can
            // have changed after the bill was staged, and the old snapshot is
            // not sufficient evidence for a ledger mutation.
            $match = $this->threeWayMatch->matchForBill($lockedBill);
            if ($match) {
                $isBlocked = $match->overallStatus === 'blocked';
                if ($isBlocked && ! $allowOverride && ! $lockedBill->three_way_overridden) {
                    // Commit the latest snapshot before returning the error so
                    // the draft visibly transitions into manual_review. The
                    // second half of this method never touches the ledger.
                    $blockedMatch = $match;
                    $lockedBill->forceFill([
                        'has_variances' => true,
                        'three_way_match_snapshot' => $match->toArray(),
                    ])->save();
                    return null;
                }
                if ($allowOverride) {
                    if (! $by->hasPermission(self::THREE_WAY_OVERRIDE_PERMISSION)) {
                        throw new BusinessRuleException('You are not authorized to approve a blocking 3-way-match override.');
                    }
                    if ($lockedBill->created_by !== null && (int) $lockedBill->created_by === (int) $by->id) {
                        throw new BusinessRuleException('The bill maker cannot also approve its 3-way-match override. A different checker must post it.');
                    }
                    $reason = trim((string) $overrideReason);
                    if ($reason === '') {
                        throw new BusinessRuleException('A reason is required when overriding a 3-way match.');
                    }
                    $lockedBill->forceFill([
                        'three_way_overridden' => $isBlocked,
                        'three_way_overridden_by' => $by->id,
                        'three_way_overridden_at' => now(),
                        'three_way_override_reason' => $reason,
                    ]);
                }
                $lockedBill->forceFill([
                    'has_variances' => $match->overallStatus !== 'matched',
                    'three_way_match_snapshot' => $match->toArray(),
                ])->save();
            }

            $this->periods->assertPostingAllowed($lockedBill->date);

            $lockedBill->loadMissing(['vendor', 'items']);
            $vendor = $lockedBill->vendor;
            $items = $lockedBill->items->map(fn (BillItem $item) => [
                'expense_account_id' => $item->expense_account_id,
                'item_id' => $item->item_id,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (string) $item->unit_price,
                'total' => (string) $item->total,
            ])->all();

            $this->postBillToGl(
                $lockedBill,
                $vendor,
                $items,
                (bool) $lockedBill->is_vatable,
                (string) $lockedBill->vat_amount,
                (string) $lockedBill->total_amount,
                $by,
            );

            $lockedBill->update(['status' => BillStatus::Unpaid]);

            // 2026-08-08 — final P2P link: posting a draft bill advances the
            // chain to the 'posted' step in real time (draft → posted → paid).
            $fresh = $lockedBill->fresh();
            app(ChainBroadcaster::class)
                ->broadcastFor($fresh, (string) $fresh->status?->value, $by);

            return $this->show($lockedBill->fresh());
        });

        if ($blockedMatch !== null) {
            throw new ThreeWayMatchException(
                '3-way match blocked by variance; manual review or an approved override is required.',
                $blockedMatch->toArray(),
            );
        }

        return $result;
    }

    public function cancel(Bill $bill, User $by, ?Carbon $reverseDate = null, ?string $reason = null): Bill
    {
        return DB::transaction(function () use ($bill, $by, $reverseDate, $reason) {
            // Lock the parent before locking its JE so cancellation uses the
            // authoritative AP state and shares the parent-first lock order
            // with recordPayment()/postDraft().
            $lockedBill = Bill::query()
                ->lockForUpdate()
                ->findOrFail($bill->getKey());
            if (! Money::isZero((string) $lockedBill->amount_paid)) {
                throw new BusinessRuleException('Cannot cancel a bill that has payments.');
            }
            if ($lockedBill->status === BillStatus::Cancelled) {
                return $lockedBill;
            }

            // Reverse the original JE if posted.
            if ($lockedBill->journal_entry_id) {
                $lockedBill->loadMissing('journalEntry');
                $je = $lockedBill->journalEntry;
                if ($je && $je->status === JournalEntryStatus::Posted) {
                    $this->journals->reverse($je, $by, $reverseDate, $reason ?? "Cancellation of bill {$lockedBill->bill_number}.");
                }
            }
            $lockedBill->update([
                'status' => BillStatus::Cancelled,
                'balance' => Money::zero(),
                'cancelled_at' => ($reverseDate ?? now()),
                'cancelled_by' => $by->id,
            ]);

            return $lockedBill->fresh();
        });
    }

    /**
     * Record a payment against an open bill.
     */
    public function recordPayment(Bill $bill, array $data, User $by): BillPayment
    {
        $amount = Money::round2((string) $data['amount']);

        return DB::transaction(function () use ($bill, $data, $amount, $by) {
            // Lock and reload before checking the bill state or balance so a
            // stale caller cannot create a second payment against settled AP.
            $lockedBill = Bill::query()
                ->lockForUpdate()
                ->findOrFail($bill->getKey());
            if ($lockedBill->status === BillStatus::Cancelled) {
                throw new BusinessRuleException('Cannot record payment on a cancelled bill.');
            }
            if ($lockedBill->status === BillStatus::Paid) {
                throw new BusinessRuleException('Bill is already fully paid.');
            }
            if (! in_array($lockedBill->status, [BillStatus::Unpaid, BillStatus::Partial], true)) {
                throw new BusinessRuleException('Payments can only be recorded against an unpaid or partially paid bill.');
            }
            if (! $lockedBill->journal_entry_id) {
                throw new BusinessRuleException('The bill must have a posted source journal before it can be paid.');
            }
            $sourceJournal = JournalEntry::query()
                ->lockForUpdate()
                ->find($lockedBill->journal_entry_id);
            if (! $sourceJournal
                || $sourceJournal->status !== JournalEntryStatus::Posted
                || $sourceJournal->reference_type !== 'bill'
                || (int) $sourceJournal->reference_id !== (int) $lockedBill->id
            ) {
                throw new BusinessRuleException('The bill source journal must be posted before payment.');
            }
            if (Money::lte($amount, '0')) {
                throw new BusinessRuleException('Payment amount must be greater than zero.');
            }
            if (Money::gt($amount, (string) $lockedBill->balance)) {
                throw new BusinessRuleException("Payment {$amount} exceeds outstanding balance ".$lockedBill->balance.'.');
            }

            $cashAccountId = HashIdFilter::decode($data['cash_account_id'], Account::class);
            if (! $cashAccountId) {
                throw new BusinessRuleException('Invalid cash account.');
            }
            $cashAccount = Account::query()->lockForUpdate()->find($cashAccountId);
            if (! $cashAccount || ! $cashAccount->is_active) {
                throw new BusinessRuleException('The selected cash account is inactive or no longer exists.');
            }
            if ($cashAccount->type !== AccountType::Asset || ! str_starts_with($cashAccount->code, '10')) {
                throw new BusinessRuleException('Payments must use an active cash or bank asset account.');
            }

            $payment = BillPayment::create([
                'bill_id' => $lockedBill->id,
                'cash_account_id' => $cashAccountId,
                'payment_date' => $data['payment_date'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'created_by' => $by->id,
                'status' => BillPaymentStatus::Posted,
            ]);

            $apId = $this->accountId($this->accounts->ap());
            $je = $this->journals->create([
                'date' => $payment->payment_date->toDateString(),
                'description' => "Payment for Bill {$lockedBill->bill_number}",
                'reference_type' => 'bill_payment',
                'reference_id' => $payment->id,
                'lines' => [
                    ['account_id' => $apId,           'debit' => $amount, 'credit' => '0.00', 'description' => 'AP settled'],
                    ['account_id' => $cashAccountId,  'debit' => '0.00',  'credit' => $amount, 'description' => 'Cash disbursed'],
                ],
            ], $by);
            $je = $this->journals->post($je, $by);

            $payment->update(['journal_entry_id' => $je->id]);

            // Update bill totals.
            $newPaid = Money::add((string) $lockedBill->amount_paid, $amount);
            $newBalance = Money::sub((string) $lockedBill->total_amount, $newPaid);
            $newStatus = Money::isZero($newBalance) ? BillStatus::Paid : BillStatus::Partial;

            $lockedBill->update([
                'amount_paid' => $newPaid,
                'balance' => $newBalance,
                'status' => $newStatus,
            ]);

            // 2026-08-08 — final P2P link: broadcast the chain step so the
            // bill detail page (and any chain view) advances in real time.
            // Partial payments move the chain to 'partial'; the settling
            // payment completes it ('paid'). The outbox dispatcher waits for
            // commit before publishing to the channel consumer.
            $fresh = $lockedBill->fresh();
            app(ChainBroadcaster::class)
                ->broadcastFor($fresh, (string) $fresh->status?->value, $by);

            return $payment->fresh(['cashAccount', 'journalEntry']);
        });
    }

    /**
     * Void a posted payment by reversing its source journal. The original
     * payment remains in the audit trail; the bill balance is rebuilt from
     * the remaining posted payments under the bill lock.
     */
    public function voidPayment(Bill $bill, BillPayment $payment, array $data, User $by): BillPayment
    {
        if (! $by->hasPermission(self::PAYMENT_VOID_PERMISSION)) {
            throw new BusinessRuleException('You are not authorized to void bill payments.');
        }

        return DB::transaction(function () use ($bill, $payment, $data, $by) {
            $lockedBill = Bill::query()->lockForUpdate()->findOrFail($bill->getKey());
            if ((int) $payment->bill_id !== (int) $lockedBill->id) {
                throw new BusinessRuleException('The payment does not belong to this bill.');
            }
            if ($lockedBill->status === BillStatus::Cancelled) {
                throw new BusinessRuleException('Cannot void a payment on a cancelled bill.');
            }

            $lockedPayment = BillPayment::query()
                ->lockForUpdate()
                ->findOrFail($payment->getKey());
            if ($lockedPayment->status !== BillPaymentStatus::Posted) {
                throw new BusinessRuleException('Only posted payments can be voided.');
            }
            if (! $lockedPayment->journal_entry_id) {
                throw new BusinessRuleException('The payment has no posted source journal and cannot be voided safely.');
            }

            $sourceJournal = JournalEntry::query()
                ->lockForUpdate()
                ->find($lockedPayment->journal_entry_id);
            if (! $sourceJournal
                || $sourceJournal->status !== JournalEntryStatus::Posted
                || $sourceJournal->reference_type !== 'bill_payment'
                || (int) $sourceJournal->reference_id !== (int) $lockedPayment->id
            ) {
                throw new BusinessRuleException('The payment source journal must be posted before it can be voided.');
            }

            $voidDate = ! empty($data['void_date'])
                ? Carbon::parse((string) $data['void_date'])
                : now();
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw new BusinessRuleException('A reason is required when voiding a bill payment.');
            }

            $replacementId = null;
            if (! empty($data['replacement_payment_id'])) {
                $replacementId = HashIdFilter::decode($data['replacement_payment_id'], BillPayment::class)
                    ?? (int) $data['replacement_payment_id'];
                $replacement = BillPayment::query()->lockForUpdate()->find($replacementId);
                if (! $replacement || (int) $replacement->bill_id !== (int) $lockedBill->id || $replacement->id === $lockedPayment->id || $replacement->status !== BillPaymentStatus::Posted) {
                    throw new BusinessRuleException('The replacement payment must be another posted payment for the same bill.');
                }
            }

            $reversal = $this->journals->reverse($sourceJournal, $by, $voidDate, $reason);

            $lockedPayment->update([
                'status' => BillPaymentStatus::Voided,
                'voided_at' => $voidDate,
                'voided_by' => $by->id,
                'void_reason' => $reason,
                'void_reversal_journal_entry_id' => $reversal->id,
                'replacement_payment_id' => $replacementId,
            ]);

            $postedPayments = BillPayment::query()
                ->where('bill_id', $lockedBill->id)
                ->where('status', BillPaymentStatus::Posted)
                ->lockForUpdate()
                ->get(['amount']);
            $newPaid = Money::zero();
            foreach ($postedPayments as $postedPayment) {
                $newPaid = Money::add($newPaid, (string) $postedPayment->amount);
            }
            $newBalance = Money::sub((string) $lockedBill->total_amount, $newPaid);
            $newStatus = Money::isZero($newBalance)
                ? BillStatus::Paid
                : (Money::isZero($newPaid) ? BillStatus::Unpaid : BillStatus::Partial);

            $lockedBill->update([
                'amount_paid' => $newPaid,
                'balance' => $newBalance,
                'status' => $newStatus,
            ]);

            $fresh = $lockedBill->fresh();
            app(ChainBroadcaster::class)
                ->broadcastFor($fresh, (string) $fresh->status?->value, $by);

            return $lockedPayment->fresh([
                'cashAccount',
                'journalEntry',
                'voidReversalJournalEntry',
                'voidedBy',
                'replacementPayment',
            ]);
        });
    }

    /**
     * Aging buckets for AP — used by Tasks 35/37.
     *
     * @return array{
     *   buckets: array{current: string, d1_30: string, d31_60: string, d61_90: string, d91_plus: string, total: string},
     *   by_vendor: array<int, array>
     * }
     */
    public function aging(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? now();
        $asOfDate = $asOf->toDateString();

        $rows = Bill::query()
            ->with([
                'vendor:id,name',
                'payments' => fn ($q) => $q
                    ->select(['id', 'bill_id', 'amount', 'payment_date', 'status'])
                    ->whereDate('payment_date', '<=', $asOfDate)
                    ->where('status', BillPaymentStatus::Posted->value),
            ])
            ->whereDate('date', '<=', $asOfDate)
            ->where(function ($q) use ($asOf) {
                $q->whereIn('status', [
                    BillStatus::Unpaid->value,
                    BillStatus::Partial->value,
                    BillStatus::Paid->value,
                ])->orWhere(function ($cancelled) use ($asOf) {
                    $cancelled->where('status', BillStatus::Cancelled->value)
                        ->whereNotNull('cancelled_at')
                        ->where('cancelled_at', '>', $asOf);
                });
            })
            ->orderBy('vendor_id')
            ->get();

        $buckets = ['current' => '0.00', 'd1_30' => '0.00', 'd31_60' => '0.00', 'd61_90' => '0.00', 'd91_plus' => '0.00', 'total' => '0.00'];
        $byVendor = [];

        foreach ($rows as $bill) {
            $paid = Money::zero();
            foreach ($bill->payments as $payment) {
                $paid = Money::add($paid, (string) $payment->amount);
            }
            $balance = Money::sub((string) $bill->total_amount, $paid);
            if (Money::lte($balance, '0')) {
                continue;
            }

            $bucket = $this->agingBucketFor($bill, $asOf);
            $buckets[$bucket] = Money::add($buckets[$bucket], $balance);
            $buckets['total'] = Money::add($buckets['total'], $balance);

            $vid = $bill->vendor_id;
            if (! isset($byVendor[$vid])) {
                $byVendor[$vid] = [
                    'vendor_id' => $bill->vendor->hash_id,
                    'vendor_name' => $bill->vendor->name,
                    'current' => '0.00',
                    'd1_30' => '0.00',
                    'd31_60' => '0.00',
                    'd61_90' => '0.00',
                    'd91_plus' => '0.00',
                    'total' => '0.00',
                ];
            }
            $byVendor[$vid][$bucket] = Money::add($byVendor[$vid][$bucket], $balance);
            $byVendor[$vid]['total'] = Money::add($byVendor[$vid]['total'], $balance);
        }

        return ['buckets' => $buckets, 'by_vendor' => array_values($byVendor)];
    }

    private function agingBucketFor(Bill $bill, Carbon $asOf): string
    {
        if (! $bill->due_date || $bill->due_date->gte($asOf)) {
            return 'current';
        }

        $days = $bill->due_date->diffInDays($asOf, true);

        return match (true) {
            $days <= 30 => 'd1_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd91_plus',
        };
    }

    public function openBalance(Vendor $vendor): string
    {
        return (string) Bill::query()
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->sum('balance');
    }

    /**
     * @return array{0: array<int, array{expense_account_id:int, item_id:?int, description:string, quantity:string, unit:?string, unit_price:string, total:string}>, 1: string}
     */
    private function assertBillProvenance(string $type, array $data, User $by): void
    {
        if ($type === 'service') {
            if (empty($data['exception_evidence']) || ! ($data['exception_approved'] ?? false)) {
                throw new BusinessRuleException('Service/non-stock bills require evidence, an owner, and explicit approval.');
            }
            if (! $by->hasPermission('accounting.bills.exception_approve')) {
                throw new BusinessRuleException('You are not authorized to approve a service/non-stock bill exception.');
            }
            return;
        }
        if ($type !== 'stock' || empty($data['purchase_order_id']) || empty($data['goods_receipt_note_id'])) {
            throw new BusinessRuleException('Stock/item bills require PO and accepted GRN provenance.');
        }
        $id = HashIdFilter::decode($data['goods_receipt_note_id'], GoodsReceiptNote::class) ?? (int) $data['goods_receipt_note_id'];
        $poId = HashIdFilter::decode($data['purchase_order_id'], PurchaseOrder::class)
            ?? (int) $data['purchase_order_id'];
        $po = PurchaseOrder::query()->lockForUpdate()->find($poId);
        if (! $po) {
            throw new BusinessRuleException('The selected purchase order no longer exists.');
        }
        $grn = GoodsReceiptNote::query()->lockForUpdate()->find($id);
        if (! $grn || $grn->status !== \App\Modules\Inventory\Enums\GrnStatus::Accepted) {
            throw new BusinessRuleException('Stock/item bills require an accepted GRN.');
        }
        if ((int) $grn->purchase_order_id !== (int) $poId) {
            throw new BusinessRuleException('The accepted GRN does not belong to the selected purchase order.');
        }
        $vendorId = HashIdFilter::decode($data['vendor_id'], Vendor::class) ?? (int) $data['vendor_id'];
        if ((int) $vendorId !== (int) $po->vendor_id || (int) $po->vendor_id !== (int) $grn->vendor_id) {
            throw new BusinessRuleException('The bill vendor must match the purchase order and accepted GRN vendor.');
        }
        if (Bill::query()->where('goods_receipt_note_id', $grn->id)->exists()) {
            throw new BusinessRuleException('An AP bill already exists for this accepted goods receipt.');
        }
    }

    private function assertPersistedBillProvenance(Bill $bill): void
    {
        if ($bill->provenance_type === 'service') {
            if (! $bill->exception_evidence || ! $bill->exception_owner_id || ! $bill->exception_approved_by) throw new BusinessRuleException('Service/non-stock bill exception evidence is incomplete.');
            return;
        }
        $po = $bill->purchase_order_id
            ? PurchaseOrder::query()->lockForUpdate()->find($bill->purchase_order_id)
            : null;
        $grn = $bill->goods_receipt_note_id
            ? GoodsReceiptNote::query()->lockForUpdate()->find($bill->goods_receipt_note_id)
            : null;
        if (! $bill->purchase_order_id || ! $grn || $grn->status !== \App\Modules\Inventory\Enums\GrnStatus::Accepted) throw new BusinessRuleException('Stock/item bills require PO and accepted GRN provenance.');
        if ((int) $grn->purchase_order_id !== (int) $bill->purchase_order_id) throw new BusinessRuleException('The accepted GRN does not belong to the bill purchase order.');
        if (! $po || (int) $po->vendor_id !== (int) $bill->vendor_id || (int) $po->vendor_id !== (int) $grn->vendor_id) {
            throw new BusinessRuleException('The bill vendor must match the purchase order and accepted GRN vendor.');
        }
    }

    /**
     * BusinessRuleException rather than ValidationException even though these
     * read like field errors: createDraft() and createDraftForGrn() are reached
     * from the supplier portal and from AutoCreateBillOnGrnAccepted, a queued
     * listener — neither submits a form with an `items` field, so keying the
     * error to one would point at nothing.
     *
     * Deliberately understated on the queue side: unlike the delivery→invoice and
     * PR→PO handoffs, AutoCreateBillOnGrnAccepted has NO catch, so these reach
     * failed_jobs either way and this conversion changes nothing there. Its live
     * effect is on the supplier-portal path, where it removes the dependence on
     * SupplierPortalController happening to wrap the call in
     * `catch (\RuntimeException)`.
     */
    private function normalizeItems(array $rawItems): array
    {
        if (count($rawItems) === 0) {
            throw new BusinessRuleException('A bill must have at least one line item.');
        }

        $rows = [];
        $subtotal = Money::zero();
        foreach ($rawItems as $raw) {
            $accountId = $this->expenseAccountId($raw['expense_account_id'] ?? null);

            $itemId = HashIdFilter::decode($raw['item_id'] ?? null, Item::class);

            $qty = Money::round2((string) $raw['quantity']);
            $price = Money::round2((string) $raw['unit_price']);
            $total = Money::round2(bcmul($qty, $price, 4));

            if (Money::lte($qty, '0') || Money::lt($price, '0')) {
                throw new BusinessRuleException('Quantity must be > 0, unit price must be ≥ 0.');
            }

            $rows[] = [
                'expense_account_id' => $accountId,
                'item_id' => $itemId,
                'description' => (string) $raw['description'],
                'quantity' => $qty,
                'unit' => $raw['unit'] ?? null,
                'unit_price' => $price,
                'total' => $total,
            ];
            $subtotal = Money::add($subtotal, $total);
        }

        return [$rows, $subtotal];
    }

    /**
     * Build + post the bill JE (DR expense lines, DR VAT Input, CR AP) and
     * link it. Shared by the manual create() path and the draft post path so
     * the ledger logic can never drift between them.
     *
     * @param  array<int, array{expense_account_id:int, item_id:?int, description:string, quantity:string, unit:?string, unit_price:string, total:string}>  $items
     */
    private function postBillToGl(Bill $bill, Vendor $vendor, array $items, bool $isVatable, string $vat, string $total, User $by): void
    {
        $apId = $this->accountId($this->accounts->ap());
        $vatInputId = $this->accountId($this->accounts->vatInput());

        $lines = [];
        foreach ($items as $row) {
            $lines[] = [
                'account_id' => $row['expense_account_id'],
                'debit' => $row['total'],
                'credit' => '0.00',
                'description' => $row['description'],
            ];
        }
        if ($isVatable && Money::gt($vat, '0')) {
            $lines[] = [
                'account_id' => $vatInputId,
                'debit' => $vat,
                'credit' => '0.00',
                'description' => 'VAT Input',
            ];
        }
        $lines[] = [
            'account_id' => $apId,
            'debit' => '0.00',
            'credit' => $total,
            'description' => "AP — {$vendor->name} · {$bill->bill_number}",
        ];

        $je = $this->journals->create([
            'date' => (string) $bill->date->toDateString(),
            'description' => "Bill {$bill->bill_number} from {$vendor->name}",
            'reference_type' => 'bill',
            'reference_id' => $bill->id,
            'lines' => $lines,
        ], $by);
        $je = $this->journals->post($je, $by);

        $bill->update(['journal_entry_id' => $je->id]);
    }

    /**
     * Resolve the default expense account (int id) for auto-created bill lines
     * — same setting the B2B portal's submitInvoice uses.
     */
    private function defaultExpenseAccountId(): ?int
    {
        $code = (string) $this->settings->get('accounting.default_expense_account_code');
        if ($code === '') {
            return null;
        }
        $id = $this->postingAccounts->configuredIdByCode($code);
        $account = Account::query()->lockForUpdate()->find($id);
        if (! $account || $account->type !== AccountType::Expense) {
            throw new BusinessRuleException("Configured default expense account {$code} must be an active expense account.");
        }

        return (int) $account->id;
    }

    private function accountId(string $code): int
    {
        return $this->accounts->controlAccountId($code);
    }

    private function expenseAccountId(mixed $value): int
    {
        $accountId = HashIdFilter::decode($value, Account::class);
        if (! $accountId) {
            throw new BusinessRuleException('Invalid expense account selected on bill item.');
        }

        $account = Account::query()->lockForUpdate()->find($accountId);
        if (! $account || ! $account->is_active) {
            throw new BusinessRuleException('The selected expense account is inactive or no longer exists.');
        }
        if ($account->type !== AccountType::Expense) {
            throw new BusinessRuleException('Bill lines must use an active expense account.');
        }

        return (int) $account->id;
    }
}
