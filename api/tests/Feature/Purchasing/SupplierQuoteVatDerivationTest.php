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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('local');
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, WorkflowSeeder::class]);
        app(SettingsService::class)->set('tax.ph.vat_rate', 0.12, 'tax');
    }

    public function test_vat_is_derived_from_quoted_lines_when_vat_exclusive(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);
        $this->uploadQuotationPdf($rfq);

        // 500 kg × ₱60 = ₱30,000 base → 12% = ₱3,600. The server derives it
        // from the quoted lines; the portal form no longer posts one.
        $quote = $this->putJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quote", [
            'vat_treatment' => 'exclusive',
            'submit' => true,
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '500.000',
                'unit_price' => '60.000',
            ]],
        ])->assertOk()->json('data');

        $this->assertSame('3600.00', $quote['vat_amount']);
    }

    public function test_vat_is_back_extracted_when_the_quote_is_declared_vat_inclusive(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);
        $this->uploadQuotationPdf($rfq);

        // ₱30,000 gross of 12% VAT → VAT component = 30000 × 12/112 = ₱3,214.29.
        $quote = $this->putJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quote", [
            'vat_treatment' => 'inclusive',
            'submit' => true,
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '500.000',
                'unit_price' => '60.000',
            ]],
        ])->assertOk()->json('data');

        $this->assertSame('3214.29', $quote['vat_amount']);
    }

    public function test_an_explicit_zero_is_honoured_as_a_no_vat_declaration(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);
        $this->uploadQuotationPdf($rfq);

        // A non-VAT-registered supplier legitimately quotes zero with
        // vat_treatment='none', regardless of the base.
        $quote = $this->putJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quote", [
            'vat_treatment' => 'none',
            'submit' => true,
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '500.000',
                'unit_price' => '60.000',
            ]],
        ])->assertOk()->json('data');

        $this->assertSame('0.00', $quote['vat_amount']);
    }



    public function test_submission_requires_at_least_one_quoted_line(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);
        $this->uploadQuotationPdf($rfq);

        // All lines are "no_quote" - nothing to evaluate, submission should fail.
        $this->putJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quote", [
            'vat_treatment' => 'exclusive',
            'submit' => true,
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'no_quote',
            ]],
        ])->assertUnprocessable();
    }

    public function test_vat_is_computed_from_lines_plus_freight(): void
    {
        [$rfq, $rfqItem, $vendor] = $this->openRfq();
        $this->actAsSupplier($vendor->id);
        $this->uploadQuotationPdf($rfq);

        // VAT base = (100 × 240) + 1200 = 25,200 → 12% = 3,024.00
        $quote = $this->putJson("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/quote", [
            'vat_treatment' => 'exclusive',
            'freight_amount' => '1200.00',
            'submit' => true,
            'items' => [[
                'request_for_quote_item_id' => $rfqItem->hash_id,
                'response_status' => 'quoted',
                'offered_quantity' => '100.000',
                'unit_price' => '240.000',
            ]],
        ])->assertOk()->json('data');

        $this->assertSame('3024.00', $quote['vat_amount']);
        $this->assertSame('1200.00', $quote['freight_amount']);
        $this->assertSame('28224.00', $quote['total_delivered_cost']);
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

        // Filter by RFQ lifecycle status
        $list(['status' => 'open'])->assertOk()->assertJsonCount(1, 'data');
        $list(['status' => 'draft'])->assertOk()->assertJsonCount(0, 'data');
        $list(['status' => 'closed'])->assertOk()->assertJsonCount(0, 'data');

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

    private function actAsSupplier(int $vendorId): SupplierPortalUser
    {
        $supplier = SupplierPortalUser::factory()->create(['vendor_id' => $vendorId, 'must_change_password' => false]);
        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');
        return $supplier;
    }

    private function uploadQuotationPdf(RequestForQuote $rfq): void
    {
        $this->post("/api/v1/b2b/supplier/rfqs/{$rfq->hash_id}/documents", [
            'document_type' => 'quotation_pdf',
            'file' => UploadedFile::fake()->create('quotation.pdf', 20, 'application/pdf'),
        ])->assertCreated();
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
            'closes_at' => now()->addDay(),
        ]);
        $rfq->forceFill([
            'status' => RfqStatus::Open,
            'issued_at' => now(),  // Required for supplier list visibility
        ])->save();
        $rfqItem = $rfq->items()->create([
            'purchase_request_item_id' => $prItem->id,
            'item_id' => $prItem->item_id,
            'description' => $prItem->description,
            'quantity' => '500.000',
            'unit' => 'kg',
        ]);
        $rfq->invitations()->create(['vendor_id' => $vendor->id, 'invited_by' => $requester->id, 'invited_at' => now()]);

        return [$rfq, $rfqItem, $vendor];
    }
}
