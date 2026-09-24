<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Listeners\DeliverRfqLifecycleNotification;
use App\Modules\Purchasing\Mail\SupplierRfqLifecycleMail;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Models\SupplierQuoteItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\RequestForQuoteService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * The simplified RFQ process end to end (docs/SUPPLIER-RFQ-BIDDING-PLAN.md):
 * start from a PR, suppliers quote in the portal or by manual capture, prices
 * stay sealed until close, one winner per line, draft POs that total exactly
 * what the comparison showed, and anything unawarded handed back to the PR.
 */
final class RfqBiddingTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, WorkflowSeeder::class]);
        Storage::fake('local');
        $this->buyer = $this->roleUser('purchasing_officer');
    }

    public function test_buyer_and_suppliers_run_an_rfq_through_to_a_draft_po(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$vendorA, $portalA] = $this->supplier();
        [$vendorB, $portalB] = $this->supplier();

        $rfq = $this->startRfq($pr, [$vendorA, $vendorB], publish: true);
        $this->assertSame('open', $rfq['status']);
        $lineId = $rfq['items'][0]['id'];
        $this->assertSame(PurchaseRequestConversionStatus::SourcingPending, $pr->fresh()->po_conversion_status);

        $this->submitQuote($portalA, $rfq['id'], $lineId, '10', '50.0000', freight: '100.00')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.goods_amount', '500.00')
            ->assertJsonPath('data.vat_amount', '72.00')
            ->assertJsonPath('data.total_delivered_cost', '672.00');

        // Sealed while open, even for the buyer; close-now waits for everyone.
        $this->asBuyer();
        $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}")
            ->assertOk()
            ->assertJsonPath('data.quotes', [])
            ->assertJsonPath('data.responded_count', 1)
            ->assertJsonPath('data.actions.can_close_now', false);
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/close")->assertStatus(422);
        $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}/comparison")->assertStatus(422);

        $this->submitQuote($portalB, $rfq['id'], $lineId, '10', '45.0000', freight: '200.00')
            ->assertJsonPath('data.total_delivered_cost', '728.00');

        $this->asBuyer();
        $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}")->assertJsonPath('data.actions.can_close_now', true);
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $quotes = collect($this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}/comparison")->assertOk()->json('data.quotes'))
            ->keyBy(fn (array $quote): string => $quote['vendor']['id']);
        $lineA = $quotes[$vendorA->hash_id]['items'][0];
        $lineB = $quotes[$vendorB->hash_id]['items'][0];
        // B's unit price is lower, but its freight makes A cheaper delivered.
        $this->assertSame('672.00', $lineA['allocated_delivered_cost']);
        $this->assertSame('67.2000', $lineA['unit_delivered_cost']);
        $this->assertSame('728.00', $lineB['allocated_delivered_cost']);
        $this->assertTrue($lineA['is_recommended']);
        $this->assertFalse($lineB['is_recommended']);

        $award = $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/award", [
            'award_reason' => 'Lowest delivered cost.',
            'lines' => [['request_for_quote_item_id' => $lineId, 'supplier_quote_item_id' => $lineA['id']]],
        ])->assertOk()->assertJsonPath('data.status', 'awarded');
        $this->assertCount(1, $award->json('purchase_orders'));

        $po = PurchaseOrder::query()->with('items')->where('request_for_quote_id', RequestForQuote::query()->value('id'))->sole();
        $this->assertSame((int) $vendorA->id, (int) $po->vendor_id);
        $this->assertSame('600.00', (string) $po->subtotal);
        $this->assertSame('72.00', (string) $po->vat_amount);
        $this->assertSame('672.00', (string) $po->total_amount);
        $this->assertSame('10.000', bcadd((string) $po->items[0]->quantity, '0', 3));
        $this->assertNotNull($po->items[0]->rfq_award_id);
        $this->assertNotNull($po->items[0]->supplier_quote_version_id);

        $this->assertSame(PurchaseRequestStatus::Converted, $pr->fresh()->status);
        $invitations = RequestForQuote::query()->sole()->invitations()->get()->keyBy('vendor_id');
        $this->assertSame('awarded', $invitations[$vendorA->id]->status->value);
        $this->assertSame('not_awarded', $invitations[$vendorB->id]->status->value);

        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/award", [
            'award_reason' => 'Again.',
            'lines' => [['request_for_quote_item_id' => $lineId, 'supplier_quote_item_id' => $lineA['id']]],
        ])->assertStatus(422)->assertJsonPath('message', 'This RFQ has already been awarded.');
    }

    public function test_a_suppliers_unsubmitted_draft_never_reaches_the_comparison(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$vendorA, $portalA] = $this->supplier();
        [$vendorB, $portalB] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendorA, $vendorB], publish: true);
        $lineId = $rfq['items'][0]['id'];

        $this->submitQuote($portalA, $rfq['id'], $lineId, '10', '60.0000');
        $this->asSupplier($portalB);
        $this->putJson("/api/v1/b2b/supplier/rfqs/{$rfq['id']}/quote", $this->quoteBody($lineId, '10', '40.0000', submit: false))
            ->assertOk()->assertJsonPath('data.status', 'draft');

        RequestForQuote::query()->sole()->forceFill(['closes_at' => now()->subMinute()])->save();
        $this->asBuyer();
        $comparison = $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}/comparison")->assertOk();
        $this->assertSame('closed', $comparison->json('data.status'));
        $this->assertSame([$vendorA->hash_id], array_column(array_column($comparison->json('data.quotes'), 'vendor'), 'id'));
        $this->assertStringNotContainsString('40.0000', $comparison->getContent());
    }

    public function test_a_submitted_quote_is_updated_by_resubmitting_and_withdraw_takes_it_out_of_evaluation(): void
    {
        [$pr, $prItem] = $this->approvedPr('10.00');
        [$vendor, $portal] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendor], publish: true);
        $lineId = $rfq['items'][0]['id'];

        $this->submitQuote($portal, $rfq['id'], $lineId, '10', '60.0000');
        $this->putJson("/api/v1/b2b/supplier/rfqs/{$rfq['id']}/quote", $this->quoteBody($lineId, '10', '55.0000', submit: false))
            ->assertStatus(422);
        $this->putJson("/api/v1/b2b/supplier/rfqs/{$rfq['id']}/quote", $this->quoteBody($lineId, '10', '55.0000', submit: true))
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.items.0.unit_price', '55.0000');
        $this->assertSame(1, SupplierQuote::query()->count());

        $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq['id']}/quote/withdraw")->assertOk()->assertJsonPath('data.status', 'draft');

        // Deadline with nothing submitted: cancelled and handed back to the PR.
        $model = RequestForQuote::query()->sole();
        $model->forceFill(['closes_at' => now()->subMinute()])->save();
        app(RequestForQuoteService::class)->closeDue($model);
        $this->assertSame(RfqStatus::Cancelled, $model->fresh()->status);
        $this->assertSame('No quotations were received before the deadline.', $model->fresh()->cancellation_reason);

        $fresh = $pr->fresh();
        $this->assertSame(PurchaseRequestStatus::Approved, $fresh->status);
        $this->assertSame(PurchaseRequestConversionStatus::NotStarted, $fresh->po_conversion_status);
        $this->assertStringContainsString('closed without quotations', (string) $fresh->po_conversion_note);
        $this->asBuyer();
        $this->getJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}")
            ->assertJsonPath('data.actions.can_start_rfq', true)
            ->assertJsonPath('data.actions.can_convert', true);
    }

    public function test_a_partial_offer_hands_the_remainder_back_for_direct_po_or_a_new_rfq(): void
    {
        [$pr, $prItem] = $this->approvedPr('10.00');
        [$vendor, $portal] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendor], publish: true);
        $lineId = $rfq['items'][0]['id'];
        $quoteLine = $this->submitQuote($portal, $rfq['id'], $lineId, '4', '50.0000')->json('data.items.0.id');

        $this->asBuyer();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/close")->assertOk();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/award", [
            'award_reason' => 'Only supplier able to deliver this month; price is within the PR estimate.',
            'lines' => [['request_for_quote_item_id' => $lineId, 'supplier_quote_item_id' => $quoteLine]],
        ])->assertOk()
            ->assertJsonPath('data.items.0.awarded_quantity', '4.0000')
            ->assertJsonPath('data.items.0.remaining_quantity', '6.0000');

        $fresh = $pr->fresh();
        $this->assertSame(PurchaseRequestStatus::Approved, $fresh->status);
        $this->assertSame(PurchaseRequestConversionStatus::Partial, $fresh->po_conversion_status);
        $this->getJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}")
            ->assertJsonPath('data.actions.can_convert', true)
            ->assertJsonPath('data.actions.can_start_rfq', true);

        // A second RFQ sources only what is left.
        $second = $this->startRfq($fresh, [$vendor], publish: false);
        $this->assertSame('6.0000', $second['items'][0]['quantity']);
        $this->postJson("/api/v1/purchasing/rfqs/{$second['id']}/cancel", ['reason' => 'Buying the rest directly.'])->assertOk();

        // ...or Direct PO converts the remainder.
        $pos = app(PurchaseOrderService::class)->convertFromPr($pr->fresh(), [$prItem->id => $vendor->id], $this->buyer);
        $this->assertSame('6.000', bcadd((string) collect($pos)->last()->items()->sole()->quantity, '0', 3));
        $this->assertSame(PurchaseRequestStatus::Converted, $pr->fresh()->status);
    }

    public function test_a_vat_inclusive_award_totals_exactly_what_was_quoted(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$vendor, $portal] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendor], publish: true);
        $lineId = $rfq['items'][0]['id'];
        $quoteLine = $this->submitQuote($portal, $rfq['id'], $lineId, '10', '112.0000', freight: '112.00', treatment: 'inclusive')
            ->assertJsonPath('data.vat_amount', '132.00')
            ->assertJsonPath('data.total_delivered_cost', '1232.00')
            ->json('data.items.0.id');

        $this->asBuyer();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/close")->assertOk();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/award", [
            'award_reason' => 'Single qualified source.',
            'lines' => [['request_for_quote_item_id' => $lineId, 'supplier_quote_item_id' => $quoteLine]],
        ])->assertOk();

        $po = PurchaseOrder::query()->with('items')->sole();
        $this->assertSame(0, bccomp('100.00', (string) $po->items[0]->unit_price, 4));
        $this->assertSame('100.00', (string) $po->rfq_freight_amount);
        $this->assertSame('1100.00', (string) $po->subtotal);
        $this->assertSame('132.00', (string) $po->vat_amount);
        $this->assertSame('1232.00', (string) $po->total_amount);
    }

    public function test_an_expired_quote_is_never_recommended_or_awarded(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$vendorA, $portalA] = $this->supplier();
        [$vendorB, $portalB] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendorA, $vendorB], publish: true);
        $lineId = $rfq['items'][0]['id'];
        $this->submitQuote($portalA, $rfq['id'], $lineId, '10', '60.0000');
        $cheapLine = $this->submitQuote($portalB, $rfq['id'], $lineId, '10', '40.0000')->json('data.items.0.id');
        SupplierQuote::query()->where('vendor_id', $vendorB->id)->update(['quote_valid_until' => now()->subDay()->toDateString()]);

        $this->asBuyer();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/close")->assertOk();
        $quotes = collect($this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}/comparison")->json('data.quotes'))
            ->keyBy(fn (array $quote): string => $quote['vendor']['id']);
        $this->assertTrue($quotes[$vendorB->hash_id]['is_expired']);
        $this->assertFalse($quotes[$vendorB->hash_id]['items'][0]['is_recommended']);
        $this->assertTrue($quotes[$vendorA->hash_id]['items'][0]['is_recommended']);

        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/award", [
            'award_reason' => 'Cheapest.',
            'lines' => [['request_for_quote_item_id' => $lineId, 'supplier_quote_item_id' => $cheapLine]],
        ])->assertStatus(422);
    }

    public function test_a_direct_po_request_that_needs_manual_sourcing_can_go_to_rfq(): void
    {
        [$pr] = $this->approvedPr('10.00', PurchaseRequestSourcingMethod::DirectPo, PurchaseRequestConversionStatus::ManualRequired, autoGenerated: false);
        [$vendor] = $this->supplier();

        $this->asBuyer();
        $this->getJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}")->assertJsonPath('data.actions.can_start_rfq', true);
        $this->startRfq($pr, [$vendor], publish: false);
        $this->assertSame(PurchaseRequestSourcingMethod::Rfq, $pr->fresh()->sourcing_method);
        $this->assertSame(PurchaseRequestConversionStatus::SourcingPending, $pr->fresh()->po_conversion_status);

        // A Direct PO request still converting automatically does not offer RFQ.
        [$converting] = $this->approvedPr('5.00', PurchaseRequestSourcingMethod::DirectPo, PurchaseRequestConversionStatus::Pending, autoGenerated: false);
        $this->getJson("/api/v1/purchasing/purchase-requests/{$converting->hash_id}")->assertJsonPath('data.actions.can_start_rfq', false);
        $this->postJson("/api/v1/purchasing/purchase-requests/{$converting->hash_id}/rfqs", $this->rfqBody([$vendor]))->assertStatus(422);
    }

    public function test_draft_rfqs_are_editable_and_invisible_to_suppliers_until_published(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$vendorA, $portalA] = $this->supplier();
        [$vendorB] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendorA], publish: false);
        $this->assertTrue($rfq['actions']['can_edit']);

        $this->asSupplier($portalA);
        $this->getJson("/api/v1/b2b/supplier/rfqs/{$rfq['id']}")->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/v1/b2b/supplier/rfqs')->json('meta.total'));

        $this->asBuyer();
        $this->putJson("/api/v1/purchasing/rfqs/{$rfq['id']}", [
            'title' => 'Resin RFQ (revised)',
            'invitations' => [
                ['vendor_id' => $vendorA->hash_id, 'exception_reason' => 'Trial supplier.'],
                ['vendor_id' => $vendorB->hash_id, 'exception_reason' => 'Trial supplier.'],
            ],
        ])->assertOk()->assertJsonPath('data.title', 'Resin RFQ (revised)')->assertJsonCount(2, 'data.invitations');
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/publish")->assertOk()->assertJsonPath('data.status', 'open');
        $this->putJson("/api/v1/purchasing/rfqs/{$rfq['id']}", ['title' => 'Too late'])->assertStatus(422);

        $this->asSupplier($portalA);
        $this->getJson("/api/v1/b2b/supplier/rfqs/{$rfq['id']}")
            ->assertOk()
            ->assertJsonPath('data.can_quote', true)
            ->assertJsonPath('data.invitation_status', 'viewed')
            ->assertJsonMissingPath('data.items.0.remaining_quantity');
    }

    public function test_manual_capture_and_role_boundaries(): void
    {
        [$pr] = $this->approvedPr('10.00');
        $phoneOnly = Vendor::factory()->create(['email' => null]);
        [$outsider, $outsiderPortal] = $this->supplier();
        $rfq = $this->startRfq($pr, [$phoneOnly], publish: true);
        $lineId = $rfq['items'][0]['id'];
        $this->assertSame('none', $rfq['invitations'][0]['reach']);

        $this->asBuyer();
        $this->post("/api/v1/purchasing/rfqs/{$rfq['id']}/documents", [
            'document_type' => 'quotation_pdf',
            'vendor_id' => $phoneOnly->hash_id,
            'file' => UploadedFile::fake()->create('phone-quote.pdf', 20, 'application/pdf'),
        ])->assertCreated();
        $quoteLine = $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/quotes/manual", [
            'vendor_id' => $phoneOnly->hash_id,
            ...$this->quoteBody($lineId, '10', '58.0000'),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.captured_manually', true)
            ->json('data.items.0.id');

        // An uninvited supplier sees nothing.
        $this->asSupplier($outsiderPortal);
        $this->getJson("/api/v1/b2b/supplier/rfqs/{$rfq['id']}")->assertNotFound();

        $this->asBuyer();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/close")->assertOk();

        // Finance and QC may review the unsealed quotations but not award.
        foreach (['finance_officer', 'qc_inspector'] as $slug) {
            $this->actingAsFresh($this->roleUser($slug));
            $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}/comparison")->assertOk()->assertJsonCount(1, 'data.quotes');
            $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/award", [
                'award_reason' => 'x',
                'lines' => [['request_for_quote_item_id' => $lineId, 'supplier_quote_item_id' => $quoteLine]],
            ])->assertForbidden();
        }
        // IT administration holds every permission but is not a buyer.
        $this->actingAsFresh($this->roleUser('system_admin'));
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/award", [
            'award_reason' => 'x',
            'lines' => [['request_for_quote_item_id' => $lineId, 'supplier_quote_item_id' => $quoteLine]],
        ])->assertStatus(422);
    }

    public function test_rfq_setup_lists_remaining_lines_and_how_each_supplier_can_be_reached(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$portalVendor] = $this->supplier();
        $emailVendor = Vendor::factory()->create(['email' => 'sales@example.test']);
        $unreachable = Vendor::factory()->create(['email' => null]);

        $this->asBuyer();
        $setup = $this->getJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/rfq-setup")->assertOk()->json('data');
        $this->assertSame('10.000', $setup['lines'][0]['remaining_quantity']);
        $this->assertTrue($setup['lines'][0]['has_item']);
        $options = collect($setup['suppliers'])->keyBy('id');
        $this->assertSame('portal', $options[$portalVendor->hash_id]['reach']);
        $this->assertSame('email', $options[$emailVendor->hash_id]['reach']);
        $this->assertSame('none', $options[$unreachable->hash_id]['reach']);
    }

    public function test_manual_entry_never_replaces_a_quote_the_supplier_entered_in_the_portal(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$vendor, $portal] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendor], publish: true);
        $lineId = $rfq['items'][0]['id'];
        $this->submitQuote($portal, $rfq['id'], $lineId, '10', '60.0000');

        $this->asBuyer();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/quotes/manual", [
            'vendor_id' => $vendor->hash_id,
            ...$this->quoteBody($lineId, '10', '1.0000'),
        ])->assertStatus(422);
        $this->assertSame('60.0000', (string) SupplierQuoteItem::query()->sole()->unit_price);
    }

    public function test_sealed_prices_never_reach_the_audit_log(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$vendor, $portal] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendor], publish: true);
        $this->submitQuote($portal, $rfq['id'], $rfq['items'][0]['id'], '10', '61.2345', freight: '99.00');

        $trail = DB::table('audit_logs')
            ->where('model_type', 'like', '%SupplierQuote%')
            ->get(['old_values', 'new_values'])
            ->map(fn ($row) => ($row->old_values ?? '').($row->new_values ?? ''))
            ->implode(' ');
        $this->assertNotSame('', $trail);
        $this->assertStringNotContainsString('61.2345', $trail);
        $this->assertStringNotContainsString('99.00', $trail);
        $this->assertStringContainsString('[sealed]', $trail);
    }

    public function test_a_request_line_without_an_inventory_item_cannot_go_to_rfq(): void
    {
        [$pr, $prItem] = $this->approvedPr('10.00');
        $prItem->forceFill(['item_id' => null])->save();
        [$vendor] = $this->supplier();

        $this->asBuyer();
        $this->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/rfqs", $this->rfqBody([$vendor]))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'inventory item'));
    }

    public function test_the_requester_can_follow_the_rfq_without_seeing_prices(): void
    {
        $requester = $this->roleUser('department_head');
        [$pr] = $this->approvedPr('10.00');
        $pr->forceFill(['requested_by' => $requester->id])->save();
        [$vendor, $portal] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendor], publish: true);
        $this->submitQuote($portal, $rfq['id'], $rfq['items'][0]['id'], '10', '60.0000');
        $this->asBuyer();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/close")->assertOk();

        $this->actingAsFresh($requester);
        $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.quotes', [])
            ->assertJsonPath('data.actions.can_award', false);
        $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}/comparison")->assertForbidden();

        $this->actingAsFresh($this->roleUser('department_head'));
        $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}")->assertForbidden();
    }

    public function test_a_passed_deadline_closes_on_read_and_cannot_be_extended(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$vendor, $portal] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vendor], publish: true);
        $this->submitQuote($portal, $rfq['id'], $rfq['items'][0]['id'], '10', '60.0000');
        RequestForQuote::query()->sole()->forceFill(['closes_at' => now()->subMinute()])->save();

        $this->asBuyer();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/extend", [
            'closes_at' => now()->addDay()->toIso8601String(),
            'reason' => 'Supplier asked for more time.',
        ])->assertStatus(422);
        // No scheduler needed: opening the RFQ closes it.
        $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.actions.can_award', true);
    }

    public function test_a_non_vat_supplier_does_not_win_just_because_its_price_has_no_vat(): void
    {
        app(SettingsService::class)->set('company.vat_status', 'VAT Registered');
        [$pr] = $this->approvedPr('10.00');
        [$vatVendor, $vatPortal] = $this->supplier();
        [$nonVatVendor, $nonVatPortal] = $this->supplier();
        $rfq = $this->startRfq($pr, [$vatVendor, $nonVatVendor], publish: true);
        $lineId = $rfq['items'][0]['id'];
        // ₱100 + 12% VAT (recoverable) vs ₱105 with no VAT: the first costs Ogami less.
        $this->submitQuote($vatPortal, $rfq['id'], $lineId, '10', '100.0000');
        $this->submitQuote($nonVatPortal, $rfq['id'], $lineId, '10', '105.0000', treatment: 'none');

        $this->asBuyer();
        $this->postJson("/api/v1/purchasing/rfqs/{$rfq['id']}/close")->assertOk();
        $comparison = $this->getJson("/api/v1/purchasing/rfqs/{$rfq['id']}/comparison")->assertOk();
        $this->assertSame('ex_vat', $comparison->json('data.ranking_basis'));
        $quotes = collect($comparison->json('data.quotes'))->keyBy(fn (array $quote): string => $quote['vendor']['id']);
        $this->assertSame('100.0000', $quotes[$vatVendor->hash_id]['items'][0]['unit_net_cost']);
        $this->assertTrue($quotes[$vatVendor->hash_id]['items'][0]['is_recommended']);
        $this->assertFalse($quotes[$nonVatVendor->hash_id]['items'][0]['is_recommended']);
    }

    public function test_notices_go_to_the_buyer_and_the_invited_suppliers(): void
    {
        [$pr] = $this->approvedPr('10.00');
        [$vendorA] = $this->supplier();
        $noEmail = Vendor::factory()->create(['email' => null]);
        $rfq = $this->startRfq($pr, [$vendorA, $noEmail], publish: true);
        $model = RequestForQuote::query()->sole();
        Mail::fake();

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')->once()->withArgs(fn ($audience, string $type): bool => $type === 'purchasing.rfq_closed'
            && $audience->pluck('id')->all() === [$this->buyer->id]);
        $listener = new DeliverRfqLifecycleNotification($notifications);

        $listener->handle(new RfqLifecycleEvent((int) $model->id, $rfq['id'], 'published'));
        Mail::assertQueued(SupplierRfqLifecycleMail::class, 1);
        $invitations = $model->invitations()->get()->keyBy('vendor_id');
        $this->assertNotNull($invitations[$vendorA->id]->email_notified_at);
        $this->assertSame('Supplier has no usable email address.', $invitations[$noEmail->id]->last_notification_error);

        $listener->handle(new RfqLifecycleEvent((int) $model->id, $rfq['id'], 'closed'));
    }

    /* ─── helpers ─── */

    /** @return array{0: PurchaseRequest, 1: PurchaseRequestItem} */
    private function approvedPr(
        string $quantity,
        PurchaseRequestSourcingMethod $method = PurchaseRequestSourcingMethod::Rfq,
        PurchaseRequestConversionStatus $conversion = PurchaseRequestConversionStatus::SourcingPending,
        bool $autoGenerated = true,
    ): array {
        $item = Item::factory()->create(['unit_of_measure' => 'kg']);
        $pr = PurchaseRequest::factory()->create(['requested_by' => $this->buyer->id, 'is_auto_generated' => $autoGenerated]);
        $pr->forceFill([
            'status' => PurchaseRequestStatus::Approved,
            'po_conversion_status' => $conversion,
            'sourcing_method' => $method,
            'required_delivery_date' => now()->addDays(30)->toDateString(),
        ])->save();
        $prItem = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => 'PP resin, natural',
            'quantity' => $quantity,
            'unit' => 'kg',
            'estimated_unit_price' => '60.00',
        ]);

        return [$pr->fresh(), $prItem];
    }

    /** @return array{0: Vendor, 1: SupplierPortalUser} */
    private function supplier(): array
    {
        $vendor = Vendor::factory()->create(['email' => fake()->unique()->safeEmail()]);
        $portal = SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'must_change_password' => false, 'is_active' => true]);

        return [$vendor, $portal];
    }

    /** @param list<Vendor> $vendors */
    private function rfqBody(array $vendors, bool $publish = false): array
    {
        return [
            'title' => 'PP resin sourcing',
            'closes_at' => now()->addDay()->toIso8601String(),
            'publish' => $publish,
            'invitations' => array_map(fn (Vendor $vendor): array => [
                'vendor_id' => $vendor->hash_id,
                'exception_reason' => 'Not yet on the approved supplier list.',
            ], $vendors),
        ];
    }

    /** @param list<Vendor> $vendors */
    private function startRfq(PurchaseRequest $pr, array $vendors, bool $publish): array
    {
        $this->asBuyer();

        return $this->postJson("/api/v1/purchasing/purchase-requests/{$pr->hash_id}/rfqs", $this->rfqBody($vendors, $publish))
            ->assertCreated()
            ->json('data');
    }

    private function quoteBody(string $lineId, string $quantity, string $price, bool $submit = true, string $freight = '0.00', string $treatment = 'exclusive'): array
    {
        return [
            'submit' => $submit,
            'vat_treatment' => $treatment,
            'freight_amount' => $freight,
            'quote_valid_until' => now()->addDays(30)->toDateString(),
            'payment_terms' => '30 days',
            'items' => [[
                'request_for_quote_item_id' => $lineId,
                'response_status' => 'quoted',
                'offered_quantity' => $quantity,
                'unit_price' => $price,
                'lead_time_days' => 7,
            ]],
        ];
    }

    private function submitQuote(SupplierPortalUser $portal, string $rfqId, string $lineId, string $quantity, string $price, string $freight = '0.00', string $treatment = 'exclusive'): TestResponse
    {
        $this->asSupplier($portal);
        $this->post("/api/v1/b2b/supplier/rfqs/{$rfqId}/documents", [
            'document_type' => 'quotation_pdf',
            'file' => UploadedFile::fake()->create('quotation.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        return $this->putJson("/api/v1/b2b/supplier/rfqs/{$rfqId}/quote", $this->quoteBody($lineId, $quantity, $price, true, $freight, $treatment))
            ->assertOk();
    }

    private function asBuyer(): void
    {
        $this->actingAsFresh($this->buyer);
    }

    private function actingAsFresh(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function asSupplier(SupplierPortalUser $portal): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($portal, ['*'], 'supplier_portal');
    }

    private function roleUser(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->value('id')]);
    }
}
