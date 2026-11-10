<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InspectionDefectPhotoSeverityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_incoming_defect_photo_records_severity_and_the_inspected_received_item(): void
    {
        [$inspection, $grnItem, $inspector] = $this->incomingInspection();
        $photo = UploadedFile::fake()->image('surface-defect.png');

        $response = $this->actingAs($inspector)->postJson($this->evidenceUrl($inspection), [
            'category' => 'surface_damage', 'severity' => 'major', 'affected_quantity' => 2,
            'notes' => 'Cracked edge on two sampled pieces.', 'photo' => $photo,
        ])->assertCreated()->assertJsonPath('data.category', 'surface_damage')
            ->assertJsonPath('data.severity', 'major')
            ->assertJsonPath('data.grn_item_id', $grnItem->hash_id);

        $this->assertArrayNotHasKey('file_path', $response->json('data'));
        $evidence = $inspection->defectEvidence()->sole();
        Storage::disk('local')->assertExists($evidence->file_path);

        $this->actingAs($inspector)->get("/api/v1/quality/defect-evidence/{$evidence->hash_id}/photo")
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_other_category_requires_a_label_and_photo_must_be_an_image(): void
    {
        [$inspection, , $inspector] = $this->incomingInspection();

        $this->actingAs($inspector)->postJson($this->evidenceUrl($inspection), [
            'category' => 'other', 'severity' => 'minor', 'affected_quantity' => 1,
            'photo' => UploadedFile::fake()->createWithContent('not-an-image.pdf', '%PDF-1.4'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['other_category', 'photo']);
    }

    public function test_defect_photo_is_private_to_quality_viewers(): void
    {
        [$inspection, , $inspector] = $this->incomingInspection();
        $photo = UploadedFile::fake()->image('defect.jpg');
        $evidence = $this->actingAs($inspector)->postJson($this->evidenceUrl($inspection), [
            'category' => 'contamination', 'severity' => 'critical', 'affected_quantity' => 1,
            'photo' => $photo,
        ])->assertCreated()->json('data.id');
        $employee = User::factory()->withRole('employee')->create(['is_active' => true]);

        $this->actingAs($employee)->get("/api/v1/quality/defect-evidence/{$evidence}/photo")->assertForbidden();
    }

    public function test_evidence_always_links_to_the_received_item_owned_by_the_inspection(): void
    {
        [$inspection, $grnItem, $inspector] = $this->incomingInspection();
        $otherItem = GrnItem::create([
            'goods_receipt_note_id' => $grnItem->goods_receipt_note_id,
            'purchase_order_item_id' => $grnItem->purchase_order_item_id,
            'item_id' => $grnItem->item_id,
            'location_id' => $grnItem->location_id,
            'quantity_received' => '1.000', 'quantity_accepted' => '0.000', 'unit_cost' => '10.0000',
        ]);

        $this->actingAs($inspector)->postJson($this->evidenceUrl($inspection), [
            'category' => 'other', 'other_category' => 'Unexpected finish', 'severity' => 'minor',
            'affected_quantity' => 1, 'grn_item_id' => $otherItem->hash_id,
            'photo' => UploadedFile::fake()->image('finish.png'),
        ])->assertCreated()->assertJsonPath('data.grn_item_id', $grnItem->hash_id);
    }

    public function test_defect_photo_upload_rejects_files_over_the_size_limit(): void
    {
        [$inspection, , $inspector] = $this->incomingInspection();

        $this->actingAs($inspector)->postJson($this->evidenceUrl($inspection), [
            'category' => 'surface_damage', 'severity' => 'minor', 'affected_quantity' => 1,
            'photo' => UploadedFile::fake()->image('too-large.png')->size(10241),
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    private function incomingInspection(): array
    {
        $inspector = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $product = Product::factory()->create();
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $po = PurchaseOrder::factory()->create();
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id,
            'description' => $item->name, 'quantity' => '20.000', 'unit' => $item->unit_of_measure,
            'unit_price' => '10.00', 'total' => '200.00', 'quantity_received' => '20.000',
        ]);
        $grn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-DEFECT-'.strtoupper(substr(uniqid(), -6)),
            'purchase_order_id' => $po->id, 'vendor_id' => $po->vendor_id ?? Vendor::factory()->create()->id,
            'received_date' => today()->toDateString(), 'received_by' => $inspector->id, 'status' => 'pending_qc',
        ]);
        $grnItem = GrnItem::create([
            'goods_receipt_note_id' => $grn->id, 'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id, 'location_id' => $location->id,
            'quantity_received' => '20.000', 'quantity_accepted' => '0.000', 'unit_cost' => '10.0000',
        ]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-DEFECT-'.strtoupper(substr(uniqid(), -6)),
            'stage' => 'incoming', 'status' => 'failed', 'product_id' => $product->id,
            'item_id' => $item->id, 'entity_type' => 'grn', 'entity_id' => $grn->id,
            'grn_item_id' => $grnItem->id, 'batch_quantity' => 20, 'sample_size' => 5,
            'accept_count' => 0, 'reject_count' => 1, 'defect_count' => 2,
            'inspector_id' => $inspector->id,
        ]);
        $inspection->forceFill(['affected_quantity' => 2])->save();

        return [$inspection, $grnItem, $inspector];
    }

    private function evidenceUrl(Inspection $inspection): string
    {
        return "/api/v1/quality/inspections/{$inspection->hash_id}/defect-evidence";
    }
}
