<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\NotificationService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Enums\SupplierQuoteStatus;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Listeners\DeliverRfqLifecycleNotification;
use App\Modules\Purchasing\Mail\SupplierRfqLifecycleMail;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteItem;
use App\Modules\Purchasing\Models\RfqDocument;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use App\Modules\Purchasing\Services\RequestForQuoteService;
use App\Modules\Purchasing\Services\RfqAwardService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class RfqBiddingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, WorkflowSeeder::class]);
    }

    public function test_supplier_quote_requires_a_server_bound_pdf_and_can_submit_the_same_version(): void
    {
        Storage::fake('local');
        [$pr, $rfq, $rfqItem, $vendor] = $this->openRfq();
        $supplier = SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'must_change_password' => false]);
        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');

        $payload = [
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '5.0000',
                'unit_price' => '60.0000',
            ]],
        ];
        $quote = $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", $payload)
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes/{$quote['id']}/submit")
            ->assertStatus(422);

        $this->post("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/documents", [
            'quote_id' => $quote['id'],
            'document_type' => 'quotation_pdf',
            'file' => UploadedFile::fake()->create('supplier-quote.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes/{$quote['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('rfq_documents', [
            'request_for_quote_id' => $rfq->id,
            'supplier_quote_id' => $quote['id'] === null ? null : SupplierQuote::query()->where('request_for_quote_id', $rfq->id)->value('id'),
            'document_type' => 'quotation_pdf',
        ]);
    }

    public function test_no_award_closure_reopens_the_pr_for_a_new_sourcing_event(): void
    {
        [$pr, $rfq] = $this->openRfq(closesAt: now()->subMinute());
        $rfq->forceFill(['status' => RfqStatus::Open])->save();

        app(RequestForQuoteService::class)->closeDue($rfq->fresh());

        $this->assertSame(RfqStatus::NoAward, $rfq->fresh()->status);
        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
        $this->assertSame(PurchaseRequestConversionStatus::NotStarted, $pr->fresh()->po_conversion_status);
    }

    public function test_award_preserves_partial_commercial_costs_and_line_traceability(): void
    {
        [$pr, $rfq, $rfqItem, $vendor] = $this->openRfq(closesAt: now()->subMinute());
        $rfq->forceFill(['status' => RfqStatus::Closed, 'closed_at' => now()])->save();
        $quote = SupplierQuote::create([
            'request_for_quote_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'invitation_id' => $rfq->invitations()->first()->id,
            'version' => 1,
            'is_current' => true,
            'vat_inclusive' => false,
            // With line VAT present the header only sums it (resolveHeaderVat).
            'vat_amount' => '10.00',
            'freight_amount' => '30.00',
            'other_charges' => '4.00',
        ]);
        $quote->forceFill(['status' => SupplierQuoteStatus::Submitted, 'submitted_at' => now()])->save();
        $quoteItem = SupplierQuoteItem::create([
            'supplier_quote_id' => $quote->id,
            'request_for_quote_item_id' => $rfqItem->id,
            'response_status' => 'quoted',
            'offered_quantity' => '10.0000',
            'unit_price' => '50.0000',
            'line_vat_amount' => '10.00',
            'line_freight_amount' => '100.00',
            'line_other_charges' => '5.00',
            'line_total_delivered_cost' => '615.00',
        ]);
        $purchasing = $this->roleUser('purchasing_officer');

        $result = app(RfqAwardService::class)->award($rfq->fresh(), [[
            'request_for_quote_item_id' => $rfqItem->id,
            'supplier_quote_item_id' => $quoteItem->id,
            'awarded_quantity' => '5.0000',
            'award_reason' => 'Lowest compliant delivered cost.',
            'single_response_justification' => 'Only invited supplier able to meet the required material grade.',
        ]], $purchasing);

        $po = $result['purchase_orders'][0]->fresh('items');
        $line = $po->items->first();
        $this->assertSame('336.50', (string) $po->subtotal);
        // Half the line VAT for half the goods; the header VAT merely summarised it.
        $this->assertSame('5.00', (string) $po->vat_amount);
        $this->assertSame('341.50', (string) $po->total_amount);
        $this->assertTrue((bool) $po->is_auto_generated);
        $this->assertSame((int) $result['rfq']->id, (int) $po->request_for_quote_id);
        $this->assertSame((int) $result['rfq']->awards()->first()->id, (int) $line->rfq_award_id);
        $this->assertSame((int) $quote->id, (int) $line->supplier_quote_version_id);
        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
        $this->assertSame(PurchaseRequestConversionStatus::Partial, $pr->fresh()->po_conversion_status);
    }

    public function test_qc_cannot_download_a_supplier_commercial_quotation(): void
    {
        Storage::fake('local');
        [$pr, $rfq, $rfqItem, $vendor] = $this->openRfq();
        Storage::disk('local')->put('rfq/private.pdf', 'pdf');
        $document = RfqDocument::create([
            'request_for_quote_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'document_type' => 'quotation_pdf',
            'original_filename' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 3,
            'file_path' => 'rfq/private.pdf',
        ]);
        $this->actingAs($this->roleUser('qc_inspector'));

        $this->get("/api/v1/purchasing/rfq-documents/{$document->hash_id}/download")
            ->assertForbidden();
    }

    public function test_supplier_cannot_open_another_vendor_invitation(): void
    {
        [$pr, $rfq] = $this->openRfq();
        $otherSupplier = SupplierPortalUser::factory()->create(['vendor_id' => Vendor::factory()->create()->id, 'must_change_password' => false]);
        Sanctum::actingAs($otherSupplier, ['*'], 'supplier_portal');

        $this->getJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}")->assertNotFound();
    }

    public function test_start_rfq_rejects_duplicate_supplier_invitations_at_validation_boundary(): void
    {
        [$pr] = $this->approvedPr();
        $vendor = Vendor::factory()->create();
        $this->actingAs($this->roleUser('purchasing_officer'));

        $this->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/rfqs", [
            'title' => 'Duplicate invitation test',
            'closes_at' => now()->addDay()->toIso8601String(),
            'invitations' => [
                ['vendor_id' => $vendor->hash_id],
                ['vendor_id' => $vendor->hash_id],
            ],
        ])->assertUnprocessable();
    }

    public function test_iso_deadlines_are_normalized_to_server_business_time(): void
    {
        [$pr] = $this->approvedPr();
        $vendor = Vendor::factory()->create();
        $this->actingAs($this->roleUser('purchasing_officer'));

        $this->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/rfqs", [
            'title' => 'Timezone-safe RFQ',
            'closes_at' => now()->utc()->addMinutes(10)->toIso8601String(),
            'invitations' => [['vendor_id' => $vendor->hash_id, 'exception_reason' => 'Approved exception for timezone test.']],
        ])->assertCreated();

        $this->assertTrue(RequestForQuote::query()->latest('id')->firstOrFail()->closes_at->isFuture());
    }

    public function test_internal_manual_capture_and_supplier_requirement_download_are_private_and_traceable(): void
    {
        Storage::fake('local');
        [$pr, $rfq, $rfqItem, $vendor] = $this->openRfq();
        $purchasing = $this->roleUser('purchasing_officer');
        $this->actingAs($purchasing);
        $document = $this->post('/api/v1/purchasing/rfqs/'.$rfq->hash_id.'/documents', [
            'document_type' => 'requirement_document',
            'file' => UploadedFile::fake()->create('resin-spec.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('data');
        $quotation = $this->post('/api/v1/purchasing/rfqs/'.$rfq->hash_id.'/documents', [
            'document_type' => 'quotation_pdf',
            'vendor_id' => $vendor->hash_id,
            'file' => UploadedFile::fake()->create('manual-quote.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/purchasing/rfqs/'.$rfq->hash_id.'/quotes/manual', [
            'vendor_id' => $vendor->hash_id,
            'quotation_document_id' => $quotation['id'],
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '5.0000',
                'unit_price' => '60.0000',
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('supplier_quotes', ['captured_by' => $purchasing->id, 'vendor_id' => $vendor->id]);
        $this->assertDatabaseHas('rfq_documents', ['id' => app('hashids')->decode($quotation['id'])[0], 'supplier_quote_id' => SupplierQuote::query()->where('captured_by', $purchasing->id)->value('id')]);
        $supplier = SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'must_change_password' => false]);
        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');
        $this->getJson('/api/v1/b2b/supplier/rfqs/'.$rfq->hash_id)->assertOk()->assertJsonPath('data.documents.0.original_filename', 'resin-spec.pdf');
        $this->get('/api/v1/b2b/supplier/rfqs/'.$rfq->hash_id.'/documents/'.$document['id'].'/download')->assertOk();
    }

    public function test_revised_quote_supersedes_the_prior_submitted_version(): void
    {
        // The partial unique index allows one current submitted quote per
        // supplier; a revision used to flip itself to submitted before
        // superseding v1, so every revision was a unique violation (500).
        Storage::fake('local');
        [$pr, $rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actingAs($this->roleUser('purchasing_officer'));
        $capture = function (string $price) use ($rfq, $rfqItem, $vendor) {
            $quotation = $this->post('/api/v1/purchasing/rfqs/'.$rfq->hash_id.'/documents', [
                'document_type' => 'quotation_pdf',
                'vendor_id' => $vendor->hash_id,
                'file' => UploadedFile::fake()->create('manual-quote.pdf', 20, 'application/pdf'),
            ])->assertCreated()->json('data');

            return $this->postJson('/api/v1/purchasing/rfqs/'.$rfq->hash_id.'/quotes/manual', [
                'vendor_id' => $vendor->hash_id,
                'quotation_document_id' => $quotation['id'],
                'items' => [[
                    'request_for_quote_item_id' => $rfqItem->hash_id,
                    'response_status' => 'quoted',
                    'offered_quantity' => '5.0000',
                    'unit_price' => $price,
                ]],
            ]);
        };
        $capture('60.0000')->assertCreated();
        $capture('55.0000')->assertCreated()->assertJsonPath('data.status', 'submitted');

        $this->assertSame(1, SupplierQuote::query()->where('request_for_quote_id', $rfq->id)->where('is_current', true)->count());
        $this->assertDatabaseHas('supplier_quotes', ['request_for_quote_id' => $rfq->id, 'version' => 1, 'is_current' => false, 'status' => SupplierQuoteStatus::Superseded->value]);
        $this->assertDatabaseHas('supplier_quotes', ['request_for_quote_id' => $rfq->id, 'version' => 2, 'is_current' => true, 'status' => SupplierQuoteStatus::Submitted->value]);

        // The supplier portal revision path goes through submit() and hit the same ordering.
        $supplier = SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'must_change_password' => false]);
        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');
        $draft = $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", ['items' => [[
            'request_for_quote_item_id' => $rfqItem->hash_id,
            'response_status' => 'quoted',
            'offered_quantity' => '5.0000',
            'unit_price' => '52.0000',
        ]]])->assertCreated()->json('data');
        $this->post("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/documents", [
            'quote_id' => $draft['id'],
            'document_type' => 'quotation_pdf',
            'file' => UploadedFile::fake()->create('supplier-quote.pdf', 20, 'application/pdf'),
        ])->assertCreated();
        $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes/{$draft['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertSame(1, SupplierQuote::query()->where('request_for_quote_id', $rfq->id)->where('is_current', true)->count());
        $this->assertDatabaseHas('supplier_quotes', ['request_for_quote_id' => $rfq->id, 'version' => 2, 'is_current' => false, 'status' => SupplierQuoteStatus::Superseded->value]);
    }

    public function test_vat_inclusive_quote_is_not_charged_vat_twice(): void
    {
        Storage::fake('local');
        [$pr, $rfq, $rfqItem, $vendor] = $this->openRfq();
        $purchasing = $this->roleUser('purchasing_officer');
        $this->actingAs($purchasing);
        $this->captureManual($rfq, $rfqItem, $vendor, '112.0000', '50.00', vatInclusive: true)
            ->assertCreated()
            ->assertJsonPath('data.vat_amount', '125.36')
            // Gross prices including freight: 1,120 + 50 = 1,170. VAT extracted from this base (12/112 rule).
            ->assertJsonPath('data.total_delivered_cost', '1170.00');

        $rfq->forceFill(['closes_at' => now()->subMinute()])->save();
        app(RequestForQuoteService::class)->closeDue($rfq->fresh());
        $result = app(RfqAwardService::class)->award($rfq->fresh(), [[
            'request_for_quote_item_id' => $rfqItem->id,
            'supplier_quote_item_id' => SupplierQuoteItem::query()->value('id'),
            'awarded_quantity' => '10.0000',
            'award_reason' => 'Lowest compliant delivered cost.',
            'single_response_justification' => 'Only invited supplier able to meet the required material grade.',
        ]], $purchasing);

        $po = $result['purchase_orders'][0]->fresh('items');
        // 10 × ₱112 + ₱50 freight, all VAT-inclusive: both goods and freight are
        // netted (100.00 each, freight 44.64) and the whole 125.36 lands as input
        // VAT — freight kept gross would capitalise its 5.36 into landed cost.
        $this->assertSame('100.00', (string) $po->items->first()->unit_price);
        $this->assertSame('44.64', (string) $po->rfq_freight_amount);
        $this->assertSame('1044.64', (string) $po->subtotal);
        $this->assertSame('125.36', (string) $po->vat_amount);
        $this->assertSame('1170.00', (string) $po->total_amount);
    }

    public function test_comparison_recommends_the_lowest_delivered_cost_including_quote_freight(): void
    {
        Storage::fake('local');
        [$pr, $rfq, $rfqItem, $cheapFreight] = $this->openRfq();
        $heavyFreight = Vendor::factory()->create();
        $rfq->invitations()->create(['vendor_id' => $heavyFreight->id, 'invited_by' => $rfq->created_by, 'invited_at' => now()]);
        $this->actingAs($this->roleUser('purchasing_officer'));
        // VAT is now computed on items + freight: both base = (items + freight) × 12%
        // cheapFreight: 950 + 117 VAT (on 975 base) + 25 = 1,092.00
        // heavyFreight: 920 + 117.60 VAT (on 980 base) + 60 = 1,097.60
        $this->captureManual($rfq, $rfqItem, $cheapFreight, '95.0000', '25.00')->assertCreated();
        $this->captureManual($rfq, $rfqItem, $heavyFreight, '92.0000', '60.00')->assertCreated();
        $rfq->forceFill(['closes_at' => now()->subMinute()])->save();

        $quotes = collect($this->getJson('/api/v1/purchasing/rfqs/'.$rfq->hash_id.'/comparison')->assertOk()->json('data.quotes'))
            ->keyBy(fn (array $quote): string => $quote['vendor']['id']);
        $cheapLine = $quotes[$cheapFreight->hash_id]['items'][0];
        $heavyLine = $quotes[$heavyFreight->hash_id]['items'][0];
        $this->assertSame('1092.00', $cheapLine['allocated_delivered_cost']);
        $this->assertSame('1097.60', $heavyLine['allocated_delivered_cost']);
        $this->assertTrue($cheapLine['is_recommended']);
        $this->assertFalse($heavyLine['is_recommended']);
    }

    public function test_expired_winning_quote_requires_supplier_reconfirmation_before_po_approval(): void
    {
        [$pr, $rfq, $rfqItem, $vendor] = $this->openRfq(closesAt: now()->subMinute());
        $rfq->forceFill(['status' => RfqStatus::Closed, 'closed_at' => now()])->save();
        $quote = SupplierQuote::create([
            'request_for_quote_id' => $rfq->id, 'vendor_id' => $vendor->id,
            'invitation_id' => $rfq->invitations()->first()->id, 'version' => 1,
            'is_current' => true, 'quote_valid_until' => now()->subDay()->toDateString(),
        ]);
        $quote->forceFill(['status' => SupplierQuoteStatus::Submitted, 'submitted_at' => now()])->save();
        $quoteItem = SupplierQuoteItem::create([
            'supplier_quote_id' => $quote->id, 'request_for_quote_item_id' => $rfqItem->id,
            'response_status' => 'quoted', 'offered_quantity' => '10.0000', 'unit_price' => '60.0000',
        ]);
        $purchasing = $this->roleUser('purchasing_officer');
        $po = app(RfqAwardService::class)->award($rfq->fresh(), [[
            'request_for_quote_item_id' => $rfqItem->id, 'supplier_quote_item_id' => $quoteItem->id,
            'awarded_quantity' => '10.0000', 'award_reason' => 'Best available offer.',
            'single_response_justification' => 'Only invited supplier responded.',
        ]], $purchasing)['purchase_orders'][0];
        $this->actingAs($purchasing);
        $this->patchJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id.'/submit')->assertUnprocessable();
        $reconfirmation = $po->rfqQuoteReconfirmation()->firstOrFail();
        $supplier = SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'must_change_password' => false]);
        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');
        $this->postJson('/api/v1/b2b/supplier/purchase-orders/'.$po->hash_id.'/rfq-reconfirmation/'.$reconfirmation->hash_id.'/confirm')->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->actingAs($purchasing);
        $this->assertNotNull(PurchaseOrder::query()->find($po->id));
        $this->patchJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id.'/submit')->assertOk()->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_rfq_lifecycle_changes_are_recorded_in_the_outbox(): void
    {
        [$pr, $rfq] = $this->openRfq();
        $rfq->forceFill(['status' => RfqStatus::Draft])->save();
        $purchasing = $this->roleUser('purchasing_officer');
        Queue::fake();

        app(RequestForQuoteService::class)->publish($rfq, $purchasing);

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => RfqLifecycleEvent::class,
            'dedupe_key' => 'rfq:'.$rfq->id.':published',
        ]);
    }

    public function test_rfq_published_delivery_updates_supplier_delivery_state(): void
    {
        [$pr, $rfq] = $this->openRfq();
        Mail::fake();
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')->once();

        (new DeliverRfqLifecycleNotification($notifications))->handle(new RfqLifecycleEvent($rfq->id, $rfq->hash_id, 'published'));

        $this->assertNotNull($rfq->invitations()->firstOrFail()->fresh()->portal_notified_at);
        $this->assertNotNull($rfq->invitations()->firstOrFail()->fresh()->email_notified_at);
        Mail::assertQueued(SupplierRfqLifecycleMail::class);
    }

    private function captureManual(RequestForQuote $rfq, RequestForQuoteItem $rfqItem, Vendor $vendor, string $price, string $freight, bool $vatInclusive = false): \Illuminate\Testing\TestResponse
    {
        $quotation = $this->post('/api/v1/purchasing/rfqs/'.$rfq->hash_id.'/documents', [
            'document_type' => 'quotation_pdf',
            'vendor_id' => $vendor->hash_id,
            'file' => UploadedFile::fake()->create('manual-quote.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('data');

        return $this->postJson('/api/v1/purchasing/rfqs/'.$rfq->hash_id.'/quotes/manual', [
            'vendor_id' => $vendor->hash_id,
            'quotation_document_id' => $quotation['id'],
            'vat_inclusive' => $vatInclusive,
            'freight_amount' => $freight,
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '10.0000',
                'unit_price' => $price,
            ]],
        ]);
    }

    /** @return array{0: PurchaseRequest, 1: RequestForQuote, 2: RequestForQuoteItem, 3: Vendor} */
    private function openRfq(\DateTimeInterface|string|null $closesAt = null): array
    {
        [$pr, $prItem, $vendor] = $this->approvedPr();
        $creator = $this->roleUser('purchasing_officer');
        $rfq = RequestForQuote::create([
            'rfq_number' => 'RFQ-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'purchase_request_id' => $pr->id,
            'created_by' => $creator->id,
            'title' => 'Production resin sourcing',
            'currency' => 'PHP',
            'closes_at' => $closesAt ?? now()->addDay(),
        ]);
        $rfq->forceFill(['status' => RfqStatus::Open])->save();
        $rfqItem = $rfq->items()->create([
            'purchase_request_item_id' => $prItem->id,
            'item_id' => $prItem->item_id,
            'description' => $prItem->description,
            'quantity' => '10.0000',
            'unit' => 'kg',
            'allow_partial_quantity' => true,
        ]);
        $rfq->invitations()->create(['vendor_id' => $vendor->id, 'invited_by' => $creator->id, 'invited_at' => now()]);

        return [$pr, $rfq, $rfqItem, $vendor];
    }

    /** @return array{0: PurchaseRequest, 1: PurchaseRequestItem, 2: Vendor} */
    private function approvedPr(): array
    {
        $requester = $this->roleUser('purchasing_officer');
        $item = Item::factory()->create(['unit_of_measure' => 'kg']);
        $pr = PurchaseRequest::factory()->create([
            'requested_by' => $requester->id,
            'is_auto_generated' => true,
        ]);
        $pr->forceFill([
            'status' => PurchaseRequestStatus::Approved,
            'po_conversion_status' => PurchaseRequestConversionStatus::NotStarted,
            'sourcing_method' => PurchaseRequestSourcingMethod::Rfq,
        ])->save();
        $prItem = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => 'Production resin',
            'quantity' => '10.00',
            'unit' => 'kg',
            'estimated_unit_price' => '60.00',
        ]);

        return [$pr, $prItem, Vendor::factory()->create()];
    }

    private function roleUser(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->value('id')]);
    }
}
