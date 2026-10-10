<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Http\UploadedFile;

trait PreparesDeliveryShipment
{
    protected function prepareShipmentForDispatch(Delivery $delivery): Delivery
    {
        $delivery->forceFill([
            'vehicle_id' => $delivery->vehicle_id ?? Vehicle::create([
                'plate_number' => 'TEST-'.substr(uniqid(), -8),
                'name' => 'Shipment test vehicle', 'vehicle_type' => 'van', 'status' => 'available',
            ])->id,
            'driver_id' => $delivery->driver_id ?? User::factory()->withRole('driver')->create(['is_active' => true])->id,
            'carrier' => 'Test carrier',
            'expected_delivery_date' => $delivery->scheduled_date->copy()->addDay()->toDateString(),
        ])->save();
        $this->attachDeliveryEvidence($delivery, 'loading_photo');

        return $delivery->fresh();
    }

    protected function attachDeliveryEvidence(Delivery $delivery, string $type = 'photo'): DeliveryProof
    {
        $file = UploadedFile::fake()->image($type.'.jpg');
        $path = $file->store("deliveries/{$delivery->id}/proofs", 'local');

        return $delivery->proofs()->create([
            'proof_type' => $type, 'file_name' => $file->getClientOriginalName(), 'file_path' => $path,
            'file_size' => $file->getSize(), 'mime_type' => $file->getMimeType(), 'uploaded_by' => $delivery->created_by,
        ]);
    }
}
