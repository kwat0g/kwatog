<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\BillPaymentStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission Phase 2 — end-to-end PROCURE-TO-PAY chain over the real HTTP API,
 * with every step performed by its real seeded role (never system_admin) and
 * real permission/chain enforcement.
 *
 * Chain: PR (buyer drafts, buyer submits) → Finance approves → VP approves →
 *   buyer converts to PO (draft) → buyer submits PO → Finance approves →
 *   VP approves → buyer sends → warehouse creates GRN (pending_qc) → QC
 *   inspector records lot result + completes → production manager (checker)
 *   reviews → warehouse accepts → stock moves + auto-bill staged → Finance
 *   posts bill → Finance records payment (pending) → Finance + VP approve →
 *   cash GL entry posted.
 *
 * Adversarial probes ride along at each stage:
 *   - skip ahead: convert before approval, accept before QC, pay before post
 *   - wrong actor: warehouse approving PRs, employee converting, buyer
 *     approving payments
 *   - wrong time: approve twice, re-send after sent, accept after accepted,
 *     pay a cancelled bill
 *   - integrity: bill math equals accepted qty × PO price, payment approval
 *     actually posts the cash GL entry
 */
class ProcureToPayChainTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $finance;
    private User $financeChecker;
    private User $vp;
    private User $warehouse;
    private User $employee;
    private User $qc;
    private User $qcChecker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(UomSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $make = fn (string $slug): User => User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);

        $this->buyer = $make('purchasing_officer');
        $this->finance = $make('finance_officer');
        // Payment approvals are maker-checker at the USER level: whoever
        // recorded the payment can never act on it, so step 1 of the
        // bill_payment chain needs a second finance_officer.
        $this->financeChecker = $make('finance_officer');
        $this->vp = $make('vice_president');
        $this->warehouse = $make('warehouse_staff');
        $this->employee = $make('employee');
        $this->qc = $make('qc_inspector');
        $this->qcChecker = $make('production_manager');

        // GRN acceptance fires the queued auto-bill listener, which attributes
        // the staged draft bill to a configured automation actor; without one
        // the listener throws and the chain silently loses its bill.
        User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Resolve a HashID from an API response payload to its model.
     * @template T
     * @param class-string<T> $class
     * @return T
     */
    private function fromApiId(string $class, ?string $hash): mixed
    {
        $this->assertNotNull($hash, 'API response must carry an id');
        $decoded = app('hashids')->decode($hash);
        $this->assertNotEmpty($decoded, "hash id did not decode: {$hash}");

        return $class::query()->findOrFail($decoded[0]);
    }

    /**
     * A postable (leaf) asset account from the seeded COA. Header accounts —
     * any account that has children — refuse new postings.
     */
    private function cashAccount(): Account
    {
        $cash = Account::query()
            ->where('type', 'asset')
            ->where('is_active', true)
            ->whereNotExists(function ($q): void {
                $q->selectRaw(1)
                    ->from('accounts as children')
                    ->whereColumn('children.parent_id', 'accounts.id');
            })
            ->orderBy('code')
            ->first();
        $this->assertNotNull($cash, 'seeded COA must contain a postable (leaf) asset account');
        $this->assertStringStartsWith('10', $cash->code, 'expected a 10xx cash-range account');

        return $cash;
    }

    private function createPr(): PurchaseRequest
    {
        $item = Item::factory()->create(['is_active' => true, 'unit_of_measure' => 'KG']);

        $res = $this->actingAs($this->buyer)
            ->postJson('/api/v1/purchasing/purchase-requests', [
                'sourcing_method' => 'direct_po',
                'items' => [[
                    'item_id' => $item->hash_id,
                    'description' => 'Resin PP-Copolymer lot '.substr(uniqid(), -5),
                'quantity' => '100.000',
                'unit' => 'KG',
                'estimated_unit_price' => '12.00',
                ]],
            ]);
        $res->assertCreated();

        return $this->fromApiId(PurchaseRequest::class, $res->json('data.id'));
    }

    private function submitAndApprovePr(PurchaseRequest $pr): void
    {
        $id = $pr->hash_id;

        $this->actingAs($this->buyer)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        // Seeded chain: Finance (step 1) → VP (step 2, threshold ₱50,000).
        // Below the threshold the VP step is skipped, so assert the terminal
        // state rather than assuming both approvals occur.
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$id}/approve")
            ->assertOk();

        $pr->refresh();
        if ($pr->status === PurchaseRequestStatus::Pending) {
            $this->actingAs($this->vp)
                ->patchJson("/api/v1/purchasing/purchase-requests/{$id}/approve")
                ->assertOk();
            $pr->refresh();
        }

        $this->assertSame('approved', $pr->status->value);
    }

    private function convertPrToPo(PurchaseRequest $pr): PurchaseOrder
    {
        $vendor = Vendor::create([
            'name' => 'Vendor-'.substr(uniqid(), -5),
            'payment_terms_days' => 30,
        ]);
        $line = $pr->items()->first();

        $this->actingAs($this->buyer)
            ->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/convert", [
                'vendor_map' => [$line->hash_id => $vendor->hash_id],
            ])
            ->assertSuccessful();

        return PurchaseOrder::query()
            ->where('purchase_request_id', $pr->id)
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->firstOrFail();
    }

    private function submitAndApprovePo(PurchaseOrder $po): void
    {
        $id = $po->hash_id;

        $this->actingAs($this->buyer)
            ->patchJson("/api/v1/purchasing/purchase-orders/{$id}/submit")
            ->assertOk();

        $this->actingAs($this->finance)
            ->patchJson("/api/v1/purchasing/purchase-orders/{$id}/approve")
            ->assertOk();

        $po->refresh();
        if ($po->status === PurchaseOrderStatus::PendingApproval) {
            $this->actingAs($this->vp)
                ->patchJson("/api/v1/purchasing/purchase-orders/{$id}/approve")
                ->assertOk();
            $po->refresh();
        }

        $this->assertSame(PurchaseOrderStatus::Approved->value, $po->status->value);
    }

    /**
     * Receive the PO fully and walk the GRN through incoming QC to Accepted.
     * @return array{0: \App\Modules\Inventory\Models\GoodsReceiptNote, 1: PurchaseOrder}
     */
    private function receivePoFully(PurchaseOrder $po): array
    {
        $grnSvc = app(GrnService::class);
        $lines = [];
        foreach ($po->items as $poItem) {
            $lines[] = [
                'purchase_order_item_id' => $poItem->id,
                'item_id' => $poItem->item_id,
                'location_id' => WarehouseLocation::factory()->create()->id,
                'quantity_received' => $poItem->quantity,
                'unit_cost' => $poItem->unit_price,
            ];
        }
        $grn = $grnSvc->create($po, $lines, ['received_date' => now()->toDateString()], $this->warehouse);
        $this->assertSame(GrnStatus::PendingQc->value, $grn->status->value, 'GRN must start pending incoming QC');

        $this->passIncomingQc($grn);

        // The AcceptGrnOnIncomingQcPass listener auto-accepts a pending_qc GRN
        // the moment a maker-checked incoming inspection passes. Accept
        // manually only if that automation left it pending (e.g. partial-accept
        // flows); the terminal state must be Accepted either way.
        $grn->refresh();
        if ($grn->status === GrnStatus::PendingQc) {
            $this->actingAs($this->warehouse)
                ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept")
                ->assertOk();
        }
        $grn->refresh();
        $this->assertSame(
            GrnStatus::Accepted->value,
            $grn->status->value,
            'a passed incoming QC must end in an accepted GRN (auto or manual)',
        );

        return [$grn->fresh(), $po->fresh()];
    }

    /**
     * Lot-result → complete (maker) → review (checker), as the QC gate expects.
     * GRN incoming inspections are maker-checker gated (requiresMakerChecker).
     */
    private function passIncomingQc(\App\Modules\Inventory\Models\GoodsReceiptNote $grn): void
    {
        $inspection = $grn->qcInspection;
        $this->assertNotNull($inspection, 'GRN must have an incoming inspection');
        $inspectionId = $inspection->hash_id;

        $rows = \App\Modules\Quality\Models\InspectionMeasurement::query()
            ->where('inspection_id', $inspection->id)
            ->get();

        // Tick every checklist row PASS with no reading (verdict-only surface).
        // checklist.*._id wants the raw integer id; `complete: true` folds the
        // sample_defect_count requirement into this single call.
        $checklist = $rows->map(fn ($r) => [
            'id' => $r->hash_id,
            'is_pass' => true,
        ])->all();

        $lotRes = $this->actingAs($this->qc)
            ->postJson("/api/v1/quality/inspections/{$inspectionId}/lot-result", [
                'checklist' => $checklist,
                'measurements' => [],
                'sample_defect_count' => 0,
                'complete' => true,
            ]);
        $lotRes->assertOk();

        $lotStatus = $lotRes->json('data.status');
        if ($lotStatus === 'awaiting_review') {
            $revRes = $this->actingAs($this->qcChecker)
                ->patchJson("/api/v1/quality/inspections/{$inspectionId}/review", [
                    'decision' => 'passed',
                ]);
            $revRes->assertOk();
        }
    }

    private function postBillAndPay(Bill $bill): BillPayment
    {
        $this->actingAs($this->finance)
            ->postJson("/api/v1/bills/{$bill->hash_id}/post", [])
            ->assertOk();

        $cash = $this->cashAccount();

        $res = $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => 'e2e-'.substr(uniqid(), -10)])
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments", [
                'cash_account_id' => $cash->hash_id,
                'payment_date' => now()->toDateString(),
                'amount' => (string) $bill->fresh()->total_amount,
                'payment_method' => 'bank_transfer',
                'reference_number' => 'BTR-'.substr(uniqid(), -6),
            ]);
        $res->assertCreated();

        return $this->fromApiId(BillPayment::class, $res->json('data.id'));
    }

    // ------------------------------------------------------------------
    // 1. HAPPY PATH — every step as its real role, ending in posted GL cash
    // ------------------------------------------------------------------

    public function test_full_procure_to_pay_chain_with_each_step_as_its_real_role(): void
    {
        $pr = $this->createPr();

        // -- Skip ahead: conversion before approval is refused --------------
        $this->actingAs($this->buyer)
            ->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/convert", [
                'vendor_map' => [$pr->items()->first()->hash_id => 1],
            ])
            ->assertStatus(422);

        $this->submitAndApprovePr($pr);

        // -- Wrong actor: warehouse holds no PR-approve permission ----------
        $this->actingAs($this->warehouse)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/approve")
            ->assertForbidden();

        // -- Wrong actor: bare employee likewise -----------------------------
        $this->actingAs($this->employee)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/approve")
            ->assertForbidden();

        $po = $this->convertPrToPo($pr);
        $this->assertSame(PurchaseOrderStatus::Draft->value, $po->status->value, 'conversion must produce a DRAFT PO');

        // -- Skip ahead: GRN creation against a draft PO is refused ---------
        try {
            app(GrnService::class)->create($po, [[
                'purchase_order_item_id' => $po->items->first()->id,
                'item_id' => $po->items->first()->item_id,
                'location_id' => WarehouseLocation::factory()->create()->id,
                'quantity_received' => '1.000',
                'unit_cost' => '12.00',
            ]], ['received_date' => now()->toDateString()], $this->warehouse);
            $this->fail('GRN creation must refuse a draft PO (skip-ahead)');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        $this->submitAndApprovePo($po);

        // -- Wrong actor: employee may not send POs --------------------------
        $this->actingAs($this->employee)
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/send")
            ->assertForbidden();

        $this->actingAs($this->buyer)
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/send")
            ->assertOk();
        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Sent->value, $po->status->value);

        // -- Wrong time: send twice is refused -------------------------------
        $this->actingAs($this->buyer)
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/send")
            ->assertStatus(422);

        [$grn, $po] = $this->receivePoFully($po);
        $this->assertSame(GrnStatus::Accepted->value, $grn->status->value);
        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Received->value, $po->status->value, 'fully received PO must move to received');

        // -- Skip ahead: accepting twice is refused ---------------------------
        $this->actingAs($this->warehouse)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept")
            ->assertStatus(422);

        $bill = Bill::query()->where('goods_receipt_note_id', $grn->id)->firstOrFail();
        $this->assertSame(BillStatus::Draft->value, $bill->status->value, 'accepting a GRN stages a draft auto-bill');

        // -- Math: bill totals equal accepted qty × PO price + 12% VAT -------
        $this->assertSame('1200.00', (string) $bill->subtotal);
        $this->assertSame('144.00', (string) $bill->vat_amount);
        $this->assertSame('1344.00', (string) $bill->total_amount);

        // -- Wrong actor: warehouse may not post bills ------------------------
        $this->actingAs($this->warehouse)
            ->postJson("/api/v1/bills/{$bill->hash_id}/post", [])
            ->assertForbidden();

        $payment = $this->postBillAndPay($bill->fresh());
        $this->assertSame(BillPaymentStatus::PendingApproval->value, $payment->status->value);

        // -- Wrong actor: buyer may not approve payments ----------------------
        $this->actingAs($this->buyer)
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments/{$payment->hash_id}/approve")
            ->assertForbidden();

        // -- Maker ≠ checker: the recording finance cannot even act on it; a
        // second finance officer is the step-1 checker -----------------------
        $this->actingAs($this->finance)
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments/{$payment->hash_id}/approve")
            ->assertForbidden();

        $this->actingAs($this->financeChecker)
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments/{$payment->hash_id}/approve")
            ->assertOk();
        $payment->refresh();
        $this->assertSame(BillPaymentStatus::PendingApproval->value, $payment->status->value, 'VP step remains after Finance');

        $this->actingAs($this->vp)
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments/{$payment->hash_id}/approve")
            ->assertOk();
        $payment->refresh();
        $this->assertSame(BillPaymentStatus::Posted->value, $payment->status->value, 'final approval posts the payment');

        // -- Integrity: the posted payment carries a GL cash entry ------------
        $this->assertNotNull($payment->fresh()->journal_entry_id, 'posted payment must carry a journal entry');

        $bill->refresh();
        $this->assertSame(BillStatus::Paid->value, $bill->status->value, 'full payment closes the bill');

        // -- Wrong time: cancelling a bill money already left is refused ------
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/bills/{$bill->hash_id}/cancel", [])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 2. SKIP-AHEAD — pay before post is refused
    // ------------------------------------------------------------------

    public function test_payment_is_refused_while_the_bill_is_still_draft(): void
    {
        $po = $this->createApprovedPoDirectly();
        [$grn] = $this->receivePoFully($po);
        $bill = Bill::query()->where('goods_receipt_note_id', $grn->id)->firstOrFail();

        $cash = $this->cashAccount();

        $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => 'e2e-skip-'.substr(uniqid(), -8)])
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments", [
                'cash_account_id' => $cash->hash_id,
                'payment_date' => now()->toDateString(),
                'amount' => (string) $bill->total_amount,
                'payment_method' => 'bank_transfer',
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 3. REJECTION PATH — rejected PR releases the lines, chain stops clean
    // ------------------------------------------------------------------

    public function test_rejected_pr_cannot_be_converted_or_paid_against(): void
    {
        $pr = $this->createPr();

        $this->actingAs($this->buyer)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/submit")
            ->assertOk();

        $this->actingAs($this->finance)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/reject", [
                'reason' => 'Duplicate of an earlier request',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // Wrong time: approve after rejection
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/approve")
            ->assertStatus(422);

        // Skip ahead: convert after rejection
        $this->actingAs($this->buyer)
            ->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/convert", [
                'vendor_map' => [$pr->items()->first()->hash_id => 1],
            ])
            ->assertStatus(422);

        $this->assertSame(0, PurchaseOrder::query()->where('purchase_request_id', $pr->id)->count());
    }

    // ------------------------------------------------------------------
    // 4. REVERSAL — a pending reservation cannot orphan a payout on a dead bill
    // ------------------------------------------------------------------

    public function test_cancelling_a_bill_with_an_open_payment_refuses_or_reverses_cleanly(): void
    {
        $po = $this->createApprovedPoDirectly();
        [$grn] = $this->receivePoFully($po);
        $bill = Bill::query()->where('goods_receipt_note_id', $grn->id)->firstOrFail();
        $payment = $this->postBillAndPay($bill->fresh());
        $this->assertSame(BillPaymentStatus::PendingApproval->value, $payment->status->value);

        // No money has moved yet (amount_paid is zero), so cancellation is
        // ALLOWED — the AP state is authoritative again.
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/bills/{$bill->hash_id}/cancel", [])
            ->assertOk();
        $bill->refresh();
        $this->assertSame(BillStatus::Cancelled->value, $bill->status->value);

        // The orphaned reservation must dead-end at EVERY approval step, not
        // just the payout: even step 1 refuses, so a dead bill never
        // accumulates approval bookkeeping for a payment that can never post.
        $this->actingAs($this->financeChecker)
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments/{$payment->hash_id}/approve")
            ->assertStatus(422);

        // And the final step stays refused too (defense in depth).
        $this->actingAs($this->vp)
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments/{$payment->hash_id}/approve")
            ->assertStatus(422);
        $payment->refresh();
        $this->assertSame(BillPaymentStatus::PendingApproval->value, $payment->status->value,
            'a pending payment on a cancelled bill must never post');

        // No approval may be RECORDED for the dead payment. The chain's
        // pending step rows are pre-created at submission (approvable_type is
        // the FQCN — no morph map is enforced); the refusal must have kept
        // every one of them 'pending' — no approver, no action, no timestamp.
        $this->assertSame(0, \App\Common\Models\ApprovalRecord::query()
            ->where('approvable_type', \App\Modules\Accounting\Models\BillPayment::class)
            ->where('approvable_id', $payment->id)
            ->whereNotNull('approver_id')
            ->count());
    }

    // ------------------------------------------------------------------
    // 5. MAKER-CHECKER — the VP cannot approve the payment they recorded
    // ------------------------------------------------------------------

    public function test_payment_maker_cannot_be_the_sole_checker(): void
    {
        $po = $this->createApprovedPoDirectly();
        [$grn] = $this->receivePoFully($po);
        $bill = Bill::query()->where('goods_receipt_note_id', $grn->id)->firstOrFail();

        $this->actingAs($this->finance)
            ->postJson("/api/v1/bills/{$bill->hash_id}/post", [])
            ->assertOk();

        $cash = $this->cashAccount();

        // VP records the payment (VP holds accounting.bills.pay? No — only
        // finance does. Use finance to record, then check that the SAME
        // finance user approving twice does not fully approve the payment:
        // the second approval must be refused or land on the VP step.)
        $res = $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => 'e2e-mc-'.substr(uniqid(), -8)])
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments", [
                'cash_account_id' => $cash->hash_id,
                'payment_date' => now()->toDateString(),
                'amount' => (string) $bill->fresh()->total_amount,
                'payment_method' => 'bank_transfer',
            ]);
        $res->assertCreated();
        $payment = $this->fromApiId(BillPayment::class, $res->json('data.id'));

        // The maker cannot act on their own payment; the second finance
        // officer approves step 1 only → still pending (VP step outstanding).
        $this->actingAs($this->finance)
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments/{$payment->hash_id}/approve")
            ->assertForbidden();
        $this->actingAs($this->financeChecker)
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments/{$payment->hash_id}/approve")
            ->assertOk();
        $payment->refresh();
        $this->assertSame(BillPaymentStatus::PendingApproval->value, $payment->status->value,
            'a single Finance approval must not post the payment');
    }

    // ------------------------------------------------------------------
    // 6. IDEMPOTENCY — double-submit of the same payment payload
    // ------------------------------------------------------------------

    public function test_double_submitted_payment_with_same_idempotency_key_pays_once(): void
    {
        $po = $this->createApprovedPoDirectly();
        [$grn] = $this->receivePoFully($po);
        $bill = Bill::query()->where('goods_receipt_note_id', $grn->id)->firstOrFail();

        $this->actingAs($this->finance)
            ->postJson("/api/v1/bills/{$bill->hash_id}/post", [])
            ->assertOk();

        $cash = $this->cashAccount();

        $idemKey = 'e2e-idem-'.substr(uniqid(), -8);
        $payload = [
            'cash_account_id' => $cash->hash_id,
            'payment_date' => now()->toDateString(),
            'amount' => (string) $bill->fresh()->total_amount,
            'payment_method' => 'bank_transfer',
        ];

        $first = $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments", $payload);
        $first->assertCreated();

        $second = $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments", $payload);
        $this->assertTrue(in_array($second->status(), [200, 201], true),
            'idempotent replay must return the same payment, not an error');

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'replay must return the original payment',
        );
        $this->assertSame(1, BillPayment::query()->where('bill_id', $this->fromApiId(Bill::class, $bill->hash_id)->id)->count(),
            'exactly one payment row must exist after a double submit');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function createApprovedPoDirectly(): PurchaseOrder
    {
        $vendor = Vendor::create([
            'name' => 'Vendor-'.substr(uniqid(), -5),
            'payment_terms_days' => 30,
        ]);
        $item = Item::factory()->create(['is_active' => true, 'unit_of_measure' => 'KG']);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.substr(uniqid(), -6),
            'vendor_id' => $vendor->id,
            'date' => now()->toDateString(),
            'total_amount' => '1200.00',
            'created_by' => $this->buyer->id,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Draft->value])->save();
        \App\Modules\Purchasing\Models\PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Adhesive resin',
            'quantity' => '100.000',
            'unit' => 'KG',
            'unit_price' => '12.00',
            'total' => '1200.00',
            'quantity_received' => '0.000',
        ]);

        $this->actingAs($this->buyer)
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/submit")
            ->assertOk();
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/approve")
            ->assertOk();
        $po->refresh();
        if ($po->status === PurchaseOrderStatus::PendingApproval) {
            $this->actingAs($this->vp)
                ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/approve")
                ->assertOk();
            $po->refresh();
        }

        return $po->fresh();
    }
}
