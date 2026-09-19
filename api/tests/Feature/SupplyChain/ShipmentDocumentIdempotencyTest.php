<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Enums\ShipmentDocumentType;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentDocument;
use App\Modules\SupplyChain\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ShipmentDocumentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_replaces_the_active_document_of_the_same_type(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $po = PurchaseOrder::factory()->create();
        $shipment = Shipment::create([
            'shipment_number' => 'SHP-DOC-'.substr(uniqid(), -6),
            'purchase_order_id' => $po->id,
            'status' => 'ordered',
            'created_by' => $user->id,
        ]);
        $service = app(ShipmentService::class);

        $first = $service->uploadDocument(
            $shipment,
            UploadedFile::fake()->create('bl-first.pdf', 1, 'application/pdf'),
            ShipmentDocumentType::BillOfLading,
            $user,
        );
        $oldPath = $first->file_path;

        $second = $service->uploadDocument(
            $shipment,
            UploadedFile::fake()->create('bl-second.pdf', 1, 'application/pdf'),
            ShipmentDocumentType::BillOfLading,
            $user,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ShipmentDocument::query()
            ->where('shipment_id', $shipment->id)
            ->where('document_type', ShipmentDocumentType::BillOfLading->value)
            ->count());
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($second->file_path);
    }
}
