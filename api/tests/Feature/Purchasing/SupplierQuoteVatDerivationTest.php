<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteItem;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The supplier quote form no longer accepts an arbitrary header VAT amount:
 * the service derives it from the quoted lines and the declared treatment.
 * These tests pin each derivation branch through the portal API.
 */
final class SupplierQuoteVatDerivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, WorkflowSeeder::class]);
        app(SettingsService::class)->set('tax.ph.vat_rate', 0.12, 'tax');
    }

    public function test_vat_is_derived_from_quoted_lines_when_vat_exclusive(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        // 500 kg × ₱60 = ₱30,000 base → 12% = ₱3,600. The server derives it
        // from the quoted lines; the portal form no longer posts one.
        $quote = $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", [
            'vat_inclusive' => false,
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '500.0000',
                'unit_price' => '60.0000',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('3600.00', $quote['vat_amount']);
    }

    public function test_vat_is_back_extracted_when_the_quote_is_declared_vat_inclusive(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        // ₱30,000 gross of 12% VAT → VAT component = 30000 × 12/112 = ₱3,214.29.
        $quote = $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", [
            'vat_inclusive' => true,
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '500.0000',
                'unit_price' => '60.0000',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('3214.29', $quote['vat_amount']);
    }

    public function test_an_explicit_zero_is_honoured_as_a_no_vat_declaration(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        // A non-VAT-registered supplier legitimately quotes zero even with a
        // taxable base. The declared zero wins over the derived 12%.
        $quote = $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", [
            'vat_inclusive' => false,
            'vat_amount' => '0.00',
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '500.0000',
                'unit_price' => '60.0000',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('0.00', $quote['vat_amount']);
    }

    public function test_line_vat_amounts_override_the_header_derivation(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        // When the supplier breaks VAT down per line, the line breakdown wins
        // over what the quoted base × rate would derive (3000 ≠ 3600).
        $quote = $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", [
            'vat_inclusive' => false,
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '500.0000',
                'unit_price' => '60.0000',
                'line_vat_amount' => '3000.00',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('3000.00', $quote['vat_amount']);
    }

    public function test_a_header_contradicting_the_line_breakdown_is_refused(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        // The lines carry ₱3,000 of VAT but the header claims ₱3,600.
        $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", [
            'vat_inclusive' => false,
            'vat_amount' => '3600.00',
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '500.0000',
                'unit_price' => '60.0000',
                'line_vat_amount' => '3000.00',
            ]],
        ])->assertUnprocessable();
    }

    public function test_a_header_vat_far_from_the_derived_value_is_refused(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        // Declared non-zero but nowhere near 12% of the ₱30,000 base.
        $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", [
            'vat_inclusive' => false,
            'vat_amount' => '100.00',
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '500.0000',
                'unit_price' => '60.0000',
            ]],
        ])->assertUnprocessable();
    }

    public function test_a_non_zero_vat_without_quoted_lines_is_refused(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", [
            'vat_inclusive' => false,
            'vat_amount' => '5000.00',
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'no_quote',
            ]],
        ])->assertUnprocessable();
    }

    public function test_vat_is_computed_from_lines_plus_freight_and_other_charges(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        // VAT base = (100 × 240) + 1200 + 150 = 25,350 → 12% = 3,042.00
        $quote = $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", [
            'vat_inclusive' => false,
            'freight_amount' => '1200.00',
            'other_charges' => '150.00',
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '100.0000',
                'unit_price' => '240.0000',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('3042.00', $quote['vat_amount']);
        $this->assertSame('1200.00', $quote['freight_amount']);
        $this->assertSame('150.00', $quote['other_charges']);
        $this->assertSame('28392.00', $quote['total_delivered_cost']);
    }

    public function test_declared_vat_mismatching_the_corrected_base_is_refused(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        // Lines-only VAT (2880) contradicts the correct base of lines + charges.
        $this->postJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quotes", [
            'vat_inclusive' => false,
            'vat_amount' => '2880.00',
            'freight_amount' => '1200.00',
            'other_charges' => '150.00',
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '100.0000',
                'unit_price' => '240.0000',
            ]],
        ])->assertUnprocessable();
    }

    public function test_supplier_rfq_list_searches_filters_and_sorts(): void
    {
        [$rfq, , $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        $list = fn (array $query) => $this->getJson('/api/v1/b2b/supplier/rfqs?'.http_build_query($query));

        // Search by RFQ number fragment...
        $list(['search' => 'RFQ'])->assertOk()->assertJsonCount(1, 'data');
        $list(['search' => 'VAT derivation'])->assertOk()->assertJsonCount(1, 'data');
        $list(['search' => 'no-such-event'])->assertOk()->assertJsonCount(0, 'data');

        // ...by lifecycle status...
        $list(['status' => 'open'])->assertOk()->assertJsonCount(1, 'data');
        $list(['status' => 'draft'])->assertOk()->assertJsonCount(0, 'data');

        // ...and by invitation status.
        $list(['status' => 'invited'])->assertOk()->assertJsonCount(1, 'data');
        $list(['status' => 'submitted'])->assertOk()->assertJsonCount(0, 'data');

        // Sorting stays deterministic on both directions.
        $list(['sort' => 'rfq_number', 'direction' => 'asc'])->assertOk()->assertJsonCount(1, 'data');
        $list(['sort' => 'closes_at', 'direction' => 'desc'])->assertOk()->assertJsonCount(1, 'data');

        // Sanity: the fixture really is the row under assertion.
        $list(['search' => 'RFQ'])->assertOk()->assertJsonPath('data.0.id', $rfq->hash_id);
    }

    public function test_supplier_rfq_search_is_case_insensitive_and_literal(): void
    {
        [$rfq, , $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);

        $list = fn (string $search) => $this
            ->getJson('/api/v1/b2b/supplier/rfqs?search='.rawurlencode($search))
            ->assertOk();

        // Case-insensitive on PostgreSQL: lowercase input matches a mixed-case title.
        $list('vat DERIVATION')->assertJsonCount(1, 'data');

        // Wildcards are literals, not operators: a bare % must not match every row.
        $list('%')->assertJsonCount(0, 'data');
        $list('_')->assertJsonCount(0, 'data');
    }

    private function actAsSupplier(int $vendorId): void
    {
        $supplier = SupplierPortalUser::factory()->create(['vendor_id' => $vendorId, 'must_change_password' => false]);
        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');
    }

    /** @return array{0: RequestForQuote, 1: RequestForQuoteItem, 2: Vendor} */
    private function openRfq(): array
    {
        $requester = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create(['unit_of_measure' => 'kg']);
        $pr = PurchaseRequest::factory()->create(['requested_by' => $requester->id]);
        $pr->forceFill([
            'status' => PurchaseRequestStatus::Approved,
            'po_conversion_status' => PurchaseRequestConversionStatus::NotStarted,
        ])->save();
        $prItem = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => 'Production resin',
            'quantity' => '500.00',
            'unit' => 'kg',
            'estimated_unit_price' => '60.00',
        ]);
        $rfq = RequestForQuote::create([
            'rfq_number' => 'RFQ-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'purchase_request_id' => $pr->id,
            'created_by' => $requester->id,
            'title' => 'VAT derivation fixture',
            'currency' => 'PHP',
            'closes_at' => now()->addDay(),
        ]);
        $rfq->forceFill(['status' => RfqStatus::Open])->save();
        $rfqItem = $rfq->items()->create([
            'purchase_request_item_id' => $prItem->id,
            'item_id' => $prItem->item_id,
            'description' => $prItem->description,
            'quantity' => '500.0000',
            'unit' => 'kg',
            'allow_partial_quantity' => true,
        ]);
        $rfq->invitations()->create(['vendor_id' => $vendor->id, 'invited_by' => $requester->id, 'invited_at' => now()]);

        return [$rfq, $rfqItem, $vendor];
    }
}
