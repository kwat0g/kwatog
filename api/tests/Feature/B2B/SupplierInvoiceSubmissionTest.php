<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Services\CurrencyDisplayService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\SupplierInvoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Events\SupplierInvoiceSubmitted;
use App\Modules\B2B\Listeners\EmailSupplierOnInvoiceSubmitted;
use App\Modules\B2B\Mail\SupplierInvoiceStatusMail;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Test supplier invoice submission: the supplier provides their invoice number
 * and date, specifies a GRN or defaults to the oldest, and the bill gets
 * stamped with supplier metadata. Idempotency via supplier_invoice_number +
 * vendor + (GRN if provided). Reuses or creates draft bills per the contract.
 */
class SupplierInvoiceSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    private function makePortalUser(?Vendor $vendor = null): SupplierPortalUser
    {
        $vendor ??= Vendor::factory()->create();

        return SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'SupUser-'.substr(uniqid(), -5),
            'email' => 'su-'.uniqid().'@t.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    private function actAs(SupplierPortalUser $user): void
    {
        Sanctum::actingAs($user, ['*'], 'supplier_portal');
    }

    private function makePo(Vendor $vendor, string $status = 'acknowledged'): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => $status])->save();

        return $po->refresh();
    }

    private function makePoItem(PurchaseOrder $po, string $quantity = '2.00'): PurchaseOrderItem
    {
        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => Item::factory()->create()->id,
            'description' => 'Test Item',
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => '100.00',
            'total' => (string) ((float) $quantity * 100.00),
            'quantity_received' => '0.00',
        ]);
    }

    private function makeAcceptedGrn(
        PurchaseOrder $po,
        Vendor $vendor,
        PurchaseOrderItem $poItem,
        string $acceptedQty = '2.00'
    ): GoodsReceiptNote {
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id' => $vendor->id,
            'status' => 'accepted',
            'accepted_by' => User::factory()->create()->id,
            'accepted_at' => now(),
        ]);

        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $poItem->item_id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => $acceptedQty,
            'quantity_accepted' => $acceptedQty,
            'unit_cost' => '100.00',
        ]);

        return $grn;
    }

    /* ─── Test Cases ─────────────────────────────────────────────── */

    public function test_later_acceptance_on_same_grn_does_not_rewrite_an_invoiced_draft(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $poItem = $this->makePoItem($po, '2.00');
        $grn = $this->makeAcceptedGrn($po, $vendor, $poItem, '1.00');
        $first = app(BillService::class)->createDraftForGrn($grn, User::factory()->create());
        $this->assertNotNull($first);

        $this->actAs($user);
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-PART-1',
            'date' => now()->toDateString(),
        ])->assertCreated();

        $grn->items()->firstOrFail()->update(['quantity_accepted' => '2.00']);
        $second = app(BillService::class)->createDraftForGrn($grn->fresh(), User::factory()->create());

        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('1.00', (string) $first->fresh()->items()->firstOrFail()->quantity);
        $this->assertSame('1.00', (string) $second->items()->firstOrFail()->quantity);
        $this->assertSame('SUP-PART-1', $first->fresh()->supplier_invoice_number);
        $this->assertTrue(SupplierPoCapabilities::invoiceableReceipts($po)->whereKey($grn->id)->exists());
    }

    public function test_one_supplier_invoice_claims_two_accepted_receipt_bills(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $po->forceFill(['is_vatable' => false])->save();
        $firstGrn = $this->makeAcceptedGrn($po, $vendor, $this->makePoItem($po), '1.00');
        $secondGrn = $this->makeAcceptedGrn($po, $vendor, $this->makePoItem($po), '1.00');
        $first = app(BillService::class)->createDraftForGrn($firstGrn, User::factory()->create());
        $second = app(BillService::class)->createDraftForGrn($secondGrn, User::factory()->create());
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $this->actAs($user);
        $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.invoiceable_bill_portions');
        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-MULTI-1',
            'date' => now()->toDateString(),
            'gross_total' => '200.00',
            'bill_ids' => [$first->hash_id, $second->hash_id],
        ]);

        $response->assertCreated()->assertJsonPath('data.supplier_invoice_number', 'SUP-MULTI-1');
        $invoice = SupplierInvoice::query()->where('number', 'SUP-MULTI-1')->firstOrFail();
        $this->assertSame(2, $invoice->bills()->count());
        $this->assertSame('200.00', (string) $invoice->gross_total);
        $this->assertSame($invoice->id, $first->fresh()->supplier_invoice_id);
        $this->assertSame($invoice->id, $second->fresh()->supplier_invoice_id);
        $this->getJson('/api/v1/b2b/supplier/supplier-invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoice->hash_id);
        $this->getJson("/api/v1/b2b/supplier/supplier-invoices/{$invoice->hash_id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.bills');

        $first->forceFill(['status' => 'cancelled'])->save();
        $second->forceFill(['status' => 'unpaid'])->save();
        $this->getJson('/api/v1/b2b/supplier/supplier-invoices?status=requires_correction')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/b2b/supplier/supplier-invoices?status=unpaid')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_grouped_invoice_rejects_amount_mismatch_and_reuses_identical_retry(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $po->forceFill(['is_vatable' => false])->save();
        $grn = $this->makeAcceptedGrn($po, $vendor, $this->makePoItem($po), '1.00');
        $bill = app(BillService::class)->createDraftForGrn($grn, User::factory()->create());
        $this->assertNotNull($bill);
        $payload = [
            'bill_number' => 'SUP-RETRY-1',
            'date' => now()->toDateString(),
            'gross_total' => '99.00',
            'bill_ids' => [$bill->hash_id],
        ];

        $this->actAs($user);
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'bill_creation_failed');
        $this->assertSame(0, SupplierInvoice::query()->count());

        $payload['gross_total'] = '100.00';
        Event::fake([SupplierInvoiceSubmitted::class]);
        $first = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload)
            ->assertCreated();
        $second = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload)
            ->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, SupplierInvoice::query()->count());
        Event::assertDispatchedTimes(SupplierInvoiceSubmitted::class, 1);
    }

    public function test_partial_receipts_can_be_invoiced_separately_and_cannot_claim_another_vendors_bill(): void
    {
        $vendor = Vendor::factory()->create();
        $supplier = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $po->forceFill(['is_vatable' => false])->save();
        $poItem = $this->makePoItem($po, '2.00');
        $first = app(BillService::class)->createDraftForGrn(
            $this->makeAcceptedGrn($po, $vendor, $poItem, '1.00'), User::factory()->create()
        );
        $second = app(BillService::class)->createDraftForGrn(
            $this->makeAcceptedGrn($po, $vendor, $poItem, '1.00'), User::factory()->create()
        );
        $otherVendor = Vendor::factory()->create();
        $otherPo = $this->makePo($otherVendor);
        $otherPo->forceFill(['is_vatable' => false])->save();
        $otherBill = app(BillService::class)->createDraftForGrn(
            $this->makeAcceptedGrn($otherPo, $otherVendor, $this->makePoItem($otherPo), '1.00'), User::factory()->create()
        );

        $this->actAs($supplier);
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-PART-A', 'date' => now()->toDateString(),
            'gross_total' => '200.00', 'bill_ids' => [$first->hash_id, $otherBill->hash_id],
        ])->assertUnprocessable();
        $this->assertSame(0, SupplierInvoice::query()->count());

        foreach ([[$first, 'SUP-PART-A'], [$second, 'SUP-PART-B']] as [$bill, $number]) {
            $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
                'bill_number' => $number, 'date' => now()->toDateString(),
                'gross_total' => '100.00', 'bill_ids' => [$bill->hash_id],
            ])->assertCreated();
        }
        $this->assertSame(2, SupplierInvoice::query()->where('vendor_id', $vendor->id)->count());
        $this->assertNotSame($first->fresh()->supplier_invoice_id, $second->fresh()->supplier_invoice_id);
    }

    public function test_supplier_invoice_confirmation_keeps_submission_values_when_records_change(): void
    {
        $vendor = Vendor::factory()->create([
            'name' => 'Snapshot Supplier',
            'contact_person' => 'Original <img src=x onerror=alert(1)> [Billing](https://evil.example) Contact',
            'email' => 'snapshot-supplier@example.test',
        ]);
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'bill_number' => 'BILL-SNAPSHOT-1',
            'date' => '2026-10-19',
            'total_amount' => '125.50',
        ]);
        $invoice = SupplierInvoice::create([
            'vendor_id' => $vendor->id,
            'number' => 'SUP-INV-42',
            'date' => '2026-10-18',
            'gross_total' => '125.50',
            'submitted_at' => now(),
        ]);
        $bill->forceFill(['supplier_invoice_id' => $invoice->id])->save();
        $event = new SupplierInvoiceSubmitted($bill->refresh());
        $mail = new SupplierInvoiceStatusMail($event->snapshot);

        $vendor->update(['contact_person' => 'Changed Contact']);
        $invoice->update(['number' => 'SUP-INV-99', 'gross_total' => '999.00']);
        $bill->update(['total_amount' => '999.00']);

        self::assertSame('SUP-INV-42', $mail->snapshot['invoice_number']);
        self::assertSame('125.50', $mail->snapshot['gross_total']);
        self::assertSame('Original <img src=x onerror=alert(1)> [Billing](https://evil.example) Contact', $mail->snapshot['supplier_name']);
        self::assertSame(app(CurrencyDisplayService::class)->format('125.50'), $mail->snapshot['display_total']);
        self::assertSame('snapshot-supplier@example.test', $mail->snapshot['recipient_email']);
        self::assertSame('[Ogami Philippines] Supplier invoice received: SUP-INV-42', $mail->envelope()->subject);
        $html = $mail->render();
        self::assertStringContainsString('SUP-INV-42', $html);
        self::assertStringContainsString('<title>Supplier invoice received</title>', $html);
        self::assertStringContainsString('We received supplier invoice SUP-INV-42 for review.', $html);
        self::assertStringContainsString($event->snapshot['display_total'], $html);
        self::assertStringNotContainsString('SUP-INV-99', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString('href="https://evil.example"', $html);
        self::assertStringContainsString('/portal/supplier/invoices/', $html);
        self::assertDoesNotMatchRegularExpression('/\{\{|undefined|>null</i', $html);
        $mail->assertSeeInText('receipt only');
    }

    public function test_supplier_invoice_email_failure_targets_only_the_bill_creator(): void
    {
        $owner = User::factory()->withRole('finance_officer')->create();
        $otherFinanceUser = User::factory()->withRole('finance_officer')->create();
        $this->assertTrue($owner->hasPermission('accounting.bills.view'));
        $this->assertTrue($otherFinanceUser->hasPermission('accounting.bills.view'));
        $vendor = Vendor::factory()->create(['email' => 'not-an-email']);
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'created_by' => $owner->id,
            'bill_number' => 'BILL-NOTICE-OWNER',
        ]);
        $event = new SupplierInvoiceSubmitted($bill);

        app(EmailSupplierOnInvoiceSubmitted::class)->handle($event);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'email.delivery_failed',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $otherFinanceUser->id,
            'type' => 'email.delivery_failed',
        ]);
        $notificationData = json_decode((string) DB::table('notifications')
            ->where('notifiable_id', $owner->id)
            ->where('type', 'email.delivery_failed')
            ->value('data'), true);
        $this->assertSame(Bill::class, $notificationData['subject_type']);
        $this->assertSame((string) $bill->id, $notificationData['subject_id']);

        (new SupplierInvoiceStatusMail($event->snapshot))->failed(new RuntimeException('Test delivery failure.'));

        $this->assertSame(2, DB::table('notifications')
            ->where('notifiable_id', $owner->id)
            ->where('type', 'email.delivery_failed')
            ->count());
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $otherFinanceUser->id,
            'type' => 'email.delivery_failed',
        ]);
    }

    public function test_pre_cutover_posted_unpaid_bill_can_be_reconciled_once(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $po->forceFill(['is_vatable' => false])->save();
        $grn = $this->makeAcceptedGrn($po, $vendor, $this->makePoItem($po), '1.00');
        $bill = app(BillService::class)->createDraftForGrn($grn, User::factory()->create());
        $this->assertNotNull($bill);
        $bill->forceFill(['status' => 'unpaid'])->save();

        $this->actAs($user);
        $this->getJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}")
            ->assertOk()->assertJsonCount(1, 'data.invoiceable_bill_portions');
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-LEGACY-POSTED',
            'date' => now()->toDateString(),
            'gross_total' => '100.00',
            'bill_ids' => [$bill->hash_id],
        ])->assertCreated();
        $this->assertNotNull($bill->fresh()->supplier_invoice_id);
    }

    public function test_grouped_invoice_file_is_stored_once_and_scoped_to_vendor(): void
    {
        Storage::fake('local');
        $vendor = Vendor::factory()->create();
        $ownUser = $this->makePortalUser($vendor);
        $otherUser = $this->makePortalUser();
        $po = $this->makePo($vendor);
        $po->forceFill(['is_vatable' => false])->save();
        $firstGrn = $this->makeAcceptedGrn($po, $vendor, $this->makePoItem($po), '1.00');
        $secondGrn = $this->makeAcceptedGrn($po, $vendor, $this->makePoItem($po), '1.00');
        $first = app(BillService::class)->createDraftForGrn($firstGrn, User::factory()->create());
        $second = app(BillService::class)->createDraftForGrn($secondGrn, User::factory()->create());

        $this->actAs($ownUser);
        $this->post("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-FILE-1', 'date' => now()->toDateString(),
            'gross_total' => '200.00', 'bill_ids' => [$first->hash_id, $second->hash_id],
            'file' => UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-test%'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $invoice = SupplierInvoice::query()->where('number', 'SUP-FILE-1')->firstOrFail();
        $document = $invoice->documents()->sole();
        Storage::disk('local')->assertExists($document->file_path);
        $this->get("/api/v1/b2b/supplier/shipping-documents/{$document->hash_id}/download")->assertOk();

        $this->actAs($otherUser);
        $this->getJson("/api/v1/b2b/supplier/supplier-invoices/{$invoice->hash_id}")->assertForbidden();
        $this->get("/api/v1/b2b/supplier/shipping-documents/{$document->hash_id}/download")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $finance = User::factory()->create(['role_id' => Role::query()->where('slug', 'finance_officer')->value('id')]);
        $this->actingAs($finance, 'web');
        foreach ([$first, $second] as $bill) {
            $this->getJson("/api/v1/bills/{$bill->hash_id}/supplier-invoice-attachment/{$document->hash_id}")
                ->assertOk();
        }
    }

    public function test_only_finance_can_void_a_submitted_draft_invoice(): void
    {
        $vendor = Vendor::factory()->create();
        $supplier = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $grn = $this->makeAcceptedGrn($po, $vendor, $this->makePoItem($po));
        $bill = app(BillService::class)->createDraftForGrn($grn, User::factory()->create());
        $this->actAs($supplier);
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-VOID-ROLE', 'date' => now()->toDateString(),
            'gross_total' => (string) $bill->total_amount,
            'bill_ids' => [$bill->hash_id],
        ])->assertCreated();
        $invoice = SupplierInvoice::query()->where('number', 'SUP-VOID-ROLE')->firstOrFail();

        $warehouse = User::factory()->create(['role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id')]);
        $this->actingAs($warehouse, 'sanctum');
        $this->postJson("/api/v1/supplier-invoices/{$invoice->hash_id}/void", [
            'reason' => 'Supplier reissued invoice.',
        ])->assertForbidden();

        $finance = User::factory()->create(['role_id' => Role::query()->where('slug', 'finance_officer')->value('id')]);
        $this->actingAs($finance, 'sanctum');
        $this->postJson("/api/v1/supplier-invoices/{$invoice->hash_id}/void", [
            'reason' => 'Supplier reissued invoice.',
        ])->assertOk();
        $this->assertNotNull($invoice->fresh()->voided_at);
        $this->assertNull($bill->fresh()->supplier_invoice_id);
    }

    /**
     * (1) Auto-staged draft exists (call BillService::createDraftForGrn) →
     * submission attaches to it: still ONE bill, supplier number stored,
     * attachment linked, message says attached.
     */
    public function test_attaches_to_existing_auto_created_draft_bill(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $poItem = $this->makePoItem($po);
        $grn = $this->makeAcceptedGrn($po, $vendor, $poItem);

        // Simulate AutoCreateBillOnGrnAccepted listener: auto-create a draft.
        $autoBill = app(BillService::class)->createDraftForGrn($grn, User::factory()->create());
        $this->assertNotNull($autoBill);

        $this->actAs($user);
        Storage::fake('local');
        $payload = [
            'bill_number' => 'SUP-INV-001',
            'date' => now()->toDateString(),
            'remarks' => 'Test invoice',
        ];
        $file = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-test%');

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload, ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.id', $autoBill->hash_id)
            ->assertJsonPath('data.supplier_invoice_number', 'SUP-INV-001');

        $bill = Bill::find($autoBill->id);
        $this->assertSame('SUP-INV-001', $bill->supplier_invoice_number);
        $this->assertSame(now()->toDateString(), $bill->supplier_invoice_date->toDateString());
        $this->assertNotNull($bill->supplier_invoice_submitted_at);
        $this->assertSame($user->id, $bill->supplier_invoice_portal_user_id);
        $this->assertSame('SUP-INV-001', $bill->supplierInvoice?->number);
        $this->assertSame(1, Bill::where('vendor_id', $vendor->id)->count());
    }

    /**
     * (2) No draft → bill created via createDraftForGrn with due date from
     * vendor payment_terms_days and zero VAT when PO is_vatable=false even
     * if the company is VAT-registered.
     */
    public function test_creates_new_draft_with_vendor_payment_terms_and_po_vat_setting(): void
    {
        $vendor = Vendor::factory()->create(['payment_terms_days' => 30]);
        $vendor2 = Vendor::factory()->create(['payment_terms_days' => 60]);
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $po->forceFill(['is_vatable' => false])->save();
        $poItem = $this->makePoItem($po);
        $grn = $this->makeAcceptedGrn($po, $vendor, $poItem);

        $this->actAs($user);
        $payload = [
            'bill_number' => 'SUP-INV-002',
            'date' => now()->toDateString(),
        ];

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload);

        $response->assertStatus(201);
        $bill = Bill::where('vendor_id', $vendor->id)->where('supplier_invoice_number', 'SUP-INV-002')->firstOrFail();

        // Due date should follow vendor payment terms from bill date.
        $expectedDueDate = now()->addDays(30)->toDateString();
        $this->assertSame($expectedDueDate, $bill->due_date->toDateString());

        // VAT should be false because the PO is not vatable, even if the company is.
        $this->assertFalse($bill->is_vatable);
    }

    /**
     * (3) Two GRNs: explicit GRN honoured; default = oldest.
     */
    public function test_honors_explicit_grn_and_defaults_to_oldest(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $poItem1 = $this->makePoItem($po);
        $poItem2 = $this->makePoItem($po);

        // Create two GRNs.
        $grn1 = $this->makeAcceptedGrn($po, $vendor, $poItem1, '1.00');
        sleep(1); // Ensure different timestamps.
        $grn2 = $this->makeAcceptedGrn($po, $vendor, $poItem2, '1.00');

        $this->actAs($user);

        // Explicit GRN: should use GRN2 even though GRN1 is older.
        $response1 = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-EXPLICIT',
            'date' => now()->toDateString(),
            'goods_receipt_note_id' => $grn2->hash_id,
        ]);
        $response1->assertStatus(201)
            ->assertJsonPath('data.goods_receipt_note.id', $grn2->hash_id);

        // Default (no explicit GRN): should use GRN1 (oldest).
        $response2 = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-DEFAULT',
            'date' => now()->toDateString(),
        ]);
        $response2->assertStatus(201)
            ->assertJsonPath('data.goods_receipt_note.id', $grn1->hash_id);
    }

    /**
     * (4) Same GRN second, different number → refused.
     */
    public function test_refuses_different_number_for_same_grn(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $poItem = $this->makePoItem($po);
        $grn = $this->makeAcceptedGrn($po, $vendor, $poItem);

        $this->actAs($user);

        // First submission.
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-FIRST',
            'date' => now()->toDateString(),
            'goods_receipt_note_id' => $grn->hash_id,
        ])->assertStatus(201);

        // Second submission for the same GRN with a different invoice number.
        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-SECOND',
            'date' => now()->toDateString(),
            'goods_receipt_note_id' => $grn->hash_id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'bill_creation_failed');
    }

    /**
     * (5) Retry same number → same bill, no duplicate event (Event::fake).
     */
    public function test_retry_same_number_is_idempotent_no_duplicate_event(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $poItem = $this->makePoItem($po);
        $grn = $this->makeAcceptedGrn($po, $vendor, $poItem);

        $this->actAs($user);
        Event::fake();

        $payload = [
            'bill_number' => 'SUP-INV-IDEMPOTENT',
            'date' => now()->toDateString(),
        ];

        $first = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload);
        $first->assertStatus(201);
        $billId1 = $first->json('data.id');

        // Clear the event log and retry.
        Event::assertDispatched(SupplierInvoiceSubmitted::class);
        Event::fake();

        $second = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", $payload);
        $second->assertStatus(201)
            ->assertJsonPath('data.id', $billId1);

        Event::assertNotDispatched(SupplierInvoiceSubmitted::class);
    }

    /**
     * (6) Same number on another PO → 422.
     */
    public function test_same_number_on_another_po_is_rejected(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po1 = $this->makePo($vendor);
        $po2 = $this->makePo($vendor);
        $poItem1 = $this->makePoItem($po1);
        $poItem2 = $this->makePoItem($po2);
        $grn1 = $this->makeAcceptedGrn($po1, $vendor, $poItem1);
        $grn2 = $this->makeAcceptedGrn($po2, $vendor, $poItem2);

        $this->actAs($user);

        // Submit for PO1.
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po1->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-SHARED',
            'date' => now()->toDateString(),
        ])->assertStatus(201);

        // Try to submit the same invoice number for PO2.
        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po2->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-SHARED',
            'date' => now()->toDateString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'bill_creation_failed');
    }

    /**
     * (7) Cancelled bill releases the number.
     */
    public function test_cancelled_bill_releases_the_invoice_number(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $poItem = $this->makePoItem($po);
        $grn = $this->makeAcceptedGrn($po, $vendor, $poItem);

        $this->actAs($user);

        // First submission.
        $response1 = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-REUSABLE',
            'date' => now()->toDateString(),
        ]);
        $response1->assertStatus(201);
        $bill = Bill::where('vendor_id', $vendor->id)->where('supplier_invoice_number', 'SUP-INV-REUSABLE')->firstOrFail();

        // Finance voids the unposted invoice; the draft becomes available again.
        app(BillService::class)->voidSupplierInvoice($bill->supplierInvoice, User::factory()->create(), 'Supplier reissued invoice.');

        // Create a second GRN and resubmit the same invoice number.
        $poItem2 = $this->makePoItem($po);
        $grn2 = $this->makeAcceptedGrn($po, $vendor, $poItem2);

        $response2 = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-REUSABLE',
            'date' => now()->toDateString(),
            'goods_receipt_note_id' => $grn2->hash_id,
        ]);

        $response2->assertStatus(201);
        $this->assertSame(2, Bill::where('vendor_id', $vendor->id)->count());
    }

    /**
     * (8) PO in `sent` → 422.
     */
    public function test_po_in_sent_status_is_rejected(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $poItem = $this->makePoItem($po);
        $grn = $this->makeAcceptedGrn($po, $vendor, $poItem);

        $this->actAs($user);

        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-SENT-PO',
            'date' => now()->toDateString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'bill_creation_failed');
    }

    /**
     * (9) GRN belonging to another PO/vendor → 422.
     */
    public function test_grn_from_different_po_or_vendor_is_rejected(): void
    {
        $vendor1 = Vendor::factory()->create();
        $vendor2 = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor1);
        $po1 = $this->makePo($vendor1);
        $po2 = $this->makePo($vendor2);
        $poItem1 = $this->makePoItem($po1);
        $poItem2 = $this->makePoItem($po2);
        $grn1 = $this->makeAcceptedGrn($po1, $vendor1, $poItem1);
        $grn2 = $this->makeAcceptedGrn($po2, $vendor2, $poItem2);

        $this->actAs($user);

        // Try to invoice GRN2 (from vendor2) against PO1 (vendor1).
        $response = $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po1->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-WRONG-GRN',
            'date' => now()->toDateString(),
            'goods_receipt_note_id' => $grn2->hash_id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'bill_creation_failed');
    }

    /**
     * (10) Internal download endpoint: permitted user gets the file, user
     * without permission 403.
     */
    public function test_internal_download_endpoint_respects_permissions(): void
    {
        $vendor = Vendor::factory()->create();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor);
        $poItem = $this->makePoItem($po);
        $grn = $this->makeAcceptedGrn($po, $vendor, $poItem);

        $this->actAs($user);
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-download-test%');
        $response = $this->post("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-INV-DOWNLOAD',
            'date' => now()->toDateString(),
            'file' => $file,
        ], ['Accept' => 'application/json']);
        $response->assertStatus(201);

        $bill = Bill::where('vendor_id', $vendor->id)->where('supplier_invoice_number', 'SUP-INV-DOWNLOAD')->firstOrFail();
        $document = $bill->portalShippingDocuments()->where('document_type', 'supplier_invoice')->firstOrFail();

        // User with accounting.bills.view permission should be able to download.
        $adminUser = User::factory()->create(['role_id' => Role::query()->where('slug', 'finance_officer')->value('id')]);

        $this->actingAs($adminUser, 'web')
            ->getJson("/api/v1/bills/{$bill->hash_id}/supplier-invoice-attachment/{$document->hash_id}")
            ->assertStatus(200);

        // User without the permission should get 403.
        $otherUser = User::factory()->create(['role_id' => Role::query()->where('slug', 'driver')->value('id')]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($otherUser, 'web')
            ->getJson("/api/v1/bills/{$bill->hash_id}/supplier-invoice-attachment/{$document->hash_id}")
            ->assertStatus(403);
    }
}
