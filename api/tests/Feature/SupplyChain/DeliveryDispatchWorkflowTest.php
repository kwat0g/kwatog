<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\SupplyChain\Enums\DeliveryProofType;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Services\DeliveryService;
use App\Modules\SupplyChain\Services\DeliveryStockReservationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\HasDeliveryStock;
use Tests\Support\PreparesDeliveryShipment;
use Tests\TestCase;

class DeliveryDispatchWorkflowTest extends TestCase
{
    use PreparesDeliveryShipment;
    use HasDeliveryStock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_future_plan_saves_assignment_and_dates_without_departure_or_stock_issue(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment(reserve: false);
        $payload = $this->plan($driver, $vehicle, dispatch: false);
        $payload['scheduled_date'] = now()->addDays(2)->toDateString();
        $payload['expected_delivery_date'] = now()->addDays(3)->toDateString();

        $this->actingAs($operator)->postJson($this->planUrl($delivery), $payload)
            ->assertOk()->assertJsonPath('data.status', 'scheduled');

        $saved = $delivery->fresh();
        $this->assertSame($driver->id, $saved->driver_id);
        $this->assertSame($vehicle->id, $saved->vehicle_id);
        $this->assertSame($payload['scheduled_date'], $saved->scheduled_date->toDateString());
        $this->assertSame($payload['expected_delivery_date'], $saved->expected_delivery_date->toDateString());
        $this->assertSame('Shipment test carrier', $saved->carrier);
        $this->assertNull($saved->departed_at);
        $this->assertNull($saved->items()->sole()->stock_movement_id);
        $this->assertSame('available', $vehicle->fresh()->status);
        $this->assertDatabaseHas('delivery_reschedules', ['delivery_id' => $delivery->id, 'to_date' => $payload['scheduled_date']]);
    }

    public function test_dispatch_plan_stores_loading_evidence_and_issues_reserved_stock_once(): void
    {
        [$delivery, $operator, $driver, $vehicle, $item, $location] = $this->shipment();
        $payload = $this->plan($driver, $vehicle);
        $payload['loading_photo'] = UploadedFile::fake()->image('loaded.jpg');

        $this->actingAs($operator)->post($this->planUrl($delivery), $payload, ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'in_transit');

        $saved = $delivery->fresh();
        $proof = $saved->proofs()->where('proof_type', 'loading_photo')->sole();
        Storage::disk('local')->assertExists($proof->file_path);
        $this->assertNotNull($saved->departed_at);
        $this->assertNull($saved->delivered_at);
        $this->assertSame('in_use', $vehicle->fresh()->status);
        $this->assertSame('0.000', (string) StockLevel::where('item_id', $item->id)->where('location_id', $location->id)->value('quantity'));
        $this->assertSame(1, $saved->items()->sole()->stockMovements()->count());

        $this->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle))->assertUnprocessable();
        $this->assertSame(1, $saved->items()->sole()->stockMovements()->count());
    }

    public function test_dispatch_without_loading_photo_rolls_back_assignment_and_dates(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $scheduledDate = $delivery->scheduled_date->toDateString();

        $this->actingAs($operator)->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle))
            ->assertUnprocessable()->assertJsonValidationErrors('loading_photo');

        $saved = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Scheduled, $saved->status);
        $this->assertNull($saved->driver_id);
        $this->assertNull($saved->vehicle_id);
        $this->assertNull($saved->expected_delivery_date);
        $this->assertNull($saved->carrier);
        $this->assertSame($scheduledDate, $saved->scheduled_date->toDateString());
        $this->assertNull($saved->departed_at);
        $this->assertNull($saved->items()->sole()->stock_movement_id);
    }

    public function test_dispatch_with_inactive_stock_hold_rolls_back_uploaded_file_and_plan(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        app(DeliveryStockReservationService::class)->releaseForDelivery($delivery);
        $payload = $this->plan($driver, $vehicle);
        $payload['loading_photo'] = UploadedFile::fake()->image('loaded.jpg');

        $this->actingAs($operator)->post($this->planUrl($delivery), $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->assertSame(DeliveryStatus::Scheduled, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->vehicle_id);
        $this->assertNull($delivery->fresh()->departed_at);
        $this->assertSame(0, $delivery->proofs()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertSame('available', $vehicle->fresh()->status);
    }

    public function test_future_dispatch_is_rejected_and_uploaded_file_is_removed(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $payload = $this->plan($driver, $vehicle);
        $payload['scheduled_date'] = now()->addDay()->toDateString();
        $payload['expected_delivery_date'] = now()->addDays(2)->toDateString();
        $payload['loading_photo'] = UploadedFile::fake()->image('loaded.jpg');

        $this->actingAs($operator)->post($this->planUrl($delivery), $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_date');

        $this->assertNull($delivery->fresh()->driver_id);
        $this->assertNull($delivery->fresh()->departed_at);
        $this->assertSame(0, $delivery->proofs()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_plan_requires_metadata_reason_and_arrival_on_or_after_shipment_date(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment(reserve: false);
        $this->actingAs($operator)->postJson($this->planUrl($delivery), ['dispatch' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id', 'driver_id', 'reason', 'scheduled_date', 'expected_delivery_date', 'carrier']);
        $payload = $this->plan($driver, $vehicle, dispatch: false);
        $payload['expected_delivery_date'] = now()->subDay()->toDateString();
        $this->postJson($this->planUrl($delivery), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('expected_delivery_date');

        foreach ([UploadedFile::fake()->create('loading.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('loading.jpg')->size(10241)] as $file) {
            $payload = $this->plan($driver, $vehicle, dispatch: false);
            $payload['loading_photo'] = $file;
            $this->post($this->planUrl($delivery), $payload, ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('loading_photo');
        }
    }

    public function test_direct_departure_cannot_bypass_metadata_and_loading_evidence(): void
    {
        [$delivery, $operator] = $this->shipment();
        $delivery->forceFill(['status' => DeliveryStatus::Loading])->save();

        $this->actingAs($operator)->patchJson($this->statusUrl($delivery), ['status' => 'in_transit'])
            ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id', 'driver_id', 'carrier', 'expected_delivery_date', 'loading_photo']);

        $this->assertNull($delivery->fresh()->departed_at);
        $this->assertNull($delivery->items()->sole()->stock_movement_id);
    }

    public function test_loading_photo_row_without_stored_file_cannot_authorize_departure(): void
    {
        [$delivery, $operator] = $this->shipment();
        $this->prepareShipmentForDispatch($delivery);
        $proof = $delivery->proofs()->where('proof_type', 'loading_photo')->sole();
        Storage::disk('local')->delete($proof->file_path);
        $delivery->forceFill(['status' => DeliveryStatus::Loading])->save();

        $this->actingAs($operator)->patchJson($this->statusUrl($delivery), ['status' => 'in_transit'])
            ->assertUnprocessable()->assertJsonValidationErrors('loading_photo');

        $this->assertSame(DeliveryStatus::Loading, $delivery->fresh()->status);
        $this->assertNull($delivery->items()->sole()->stock_movement_id);
    }

    public function test_saved_loading_photo_can_be_used_for_later_dispatch(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $payload = $this->plan($driver, $vehicle, dispatch: false);
        $payload['loading_photo'] = UploadedFile::fake()->image('loaded.jpg');
        $this->actingAs($operator)->post($this->planUrl($delivery), $payload, ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');

        $this->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle))
            ->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->assertSame(1, $delivery->proofs()->where('proof_type', 'loading_photo')->count());
    }

    public function test_loading_plan_retains_existing_assignment(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $this->actingAs($operator)->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle, dispatch: false))->assertOk();
        app(DeliveryService::class)->updateStatus($delivery->fresh(), DeliveryStatus::Loading);
        $replacement = User::factory()->withRole('driver')->create(['is_active' => true]);

        $this->postJson($this->planUrl($delivery), $this->plan($replacement, $vehicle, dispatch: false))->assertUnprocessable();
        $this->assertSame($driver->id, $delivery->fresh()->driver_id);
        $this->postJson($this->planUrl($delivery), $this->plan($driver, $vehicle, dispatch: false))
            ->assertOk()->assertJsonPath('data.status', 'loading');
    }

    public function test_loading_evidence_cannot_record_arrival_confirm_delivery_or_be_deleted_after_dispatch(): void
    {
        [$delivery, $operator] = $this->shipment();
        $this->prepareShipmentForDispatch($delivery);
        $delivery->forceFill(['status' => DeliveryStatus::Loading])->save();
        app(DeliveryService::class)->updateStatus($delivery->fresh(), DeliveryStatus::InTransit);
        $proof = $delivery->proofs()->where('proof_type', 'loading_photo')->sole();

        $this->actingAs($operator)->patchJson($this->statusUrl($delivery), ['status' => 'delivered'])->assertUnprocessable();
        $this->deleteJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/proofs/'.$proof->hash_id)->assertUnprocessable();
        $this->post('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/proofs', [
            'proof_type' => 'loading_photo', 'file' => UploadedFile::fake()->image('late-loading.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(DeliveryStatus::InTransit, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->delivered_at);
        $this->assertSame(1, $delivery->proofs()->count());

        $delivery->forceFill(['status' => DeliveryStatus::Delivered])->save();
        $this->postJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/confirm', [])->assertUnprocessable();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
    }

    public function test_generic_customer_receipt_upload_records_arrival_and_reconciles_quantity(): void
    {
        foreach (['photo', 'signed_dr', 'customer_po_confirmation'] as $type) {
            [$delivery, $operator, $driver, $vehicle] = $this->shipment();
            $delivery->forceFill(['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id])->save();
            $this->prepareShipmentForDispatch($delivery);
            $delivery->forceFill(['status' => DeliveryStatus::Loading])->save();
            app(DeliveryService::class)->updateStatus($delivery->fresh(), DeliveryStatus::InTransit);

            $this->actingAs($driver);
            app(DeliveryService::class)->uploadProof($delivery->fresh(), UploadedFile::fake()->image('receipt.jpg'), DeliveryProofType::from($type), $driver);

            $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
            $this->assertNotNull($delivery->fresh()->delivered_at);
            $this->assertSame('3.00', (string) $delivery->items()->sole()->salesOrderItem->quantity_delivered);
            $proof = $delivery->proofs()->where('proof_type', $type)->sole();
            Storage::disk('local')->assertExists($proof->file_path);
        }
    }

    public function test_certificate_upload_does_not_record_customer_arrival(): void
    {
        [$delivery, $operator] = $this->shipment();
        $this->prepareShipmentForDispatch($delivery);
        $delivery->forceFill(['status' => DeliveryStatus::Loading])->save();
        app(DeliveryService::class)->updateStatus($delivery->fresh(), DeliveryStatus::InTransit);

        $this->actingAs($operator)->post('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/proofs', [
            'proof_type' => 'coc', 'file' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(DeliveryStatus::InTransit, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->delivered_at);
    }

    public function test_customer_receipt_cannot_record_arrival_before_dispatch(): void
    {
        [$delivery, $operator, $driver] = $this->shipment(reserve: false);
        $delivery->forceFill(['driver_id' => $driver->id])->save();

        $this->actingAs($driver)->post('/api/v1/driver/deliveries/'.$delivery->hash_id.'/receipt', [
            'photo' => UploadedFile::fake()->image('early-receipt.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame(DeliveryStatus::Scheduled, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->delivered_at);
        $this->assertSame(0, $delivery->proofs()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_operator_cannot_upload_customer_receipt_through_internal_endpoints(): void
    {
        [$delivery, $operator, $driver, $vehicle] = $this->shipment();
        $delivery->forceFill(['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id])->save();
        $this->prepareShipmentForDispatch($delivery);
        $delivery->forceFill(['status' => DeliveryStatus::Loading])->save();
        app(DeliveryService::class)->updateStatus($delivery->fresh(), DeliveryStatus::InTransit);

        $this->actingAs($operator)->post('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/receipt', [
            'file' => UploadedFile::fake()->image('office-receipt.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        foreach (['photo', 'signed_dr', 'customer_po_confirmation'] as $type) {
            $this->post('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/proofs', [
                'proof_type' => $type, 'file' => UploadedFile::fake()->image('office-proof.jpg'),
            ], ['Accept' => 'application/json'])->assertForbidden();
        }

        $this->assertSame(DeliveryStatus::InTransit, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->receipt_photo_path);
        $this->assertNull($delivery->fresh()->delivered_at);
        $this->assertSame(1, $delivery->proofs()->count());
    }

    public function test_certificate_and_loading_evidence_cannot_authorize_confirmation(): void
    {
        [$delivery, $operator] = $this->shipment();
        $this->attachDeliveryEvidence($delivery, 'loading_photo');
        $this->attachDeliveryEvidence($delivery, 'coc');
        $delivery->forceFill(['status' => DeliveryStatus::Delivered])->save();

        $this->actingAs($operator)->getJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id)
            ->assertOk()->assertJsonPath('data.can_confirm', false);
        try {
            app(DeliveryService::class)->confirm($delivery->fresh(), $operator);
            $this->fail('A certificate and loading photo cannot prove customer receipt.');
        } catch (\App\Common\Exceptions\BusinessRuleException $error) {
            $this->assertStringContainsString('proof of delivery', $error->getMessage());
        }

        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->confirmed_at);
        $this->assertNull($delivery->fresh()->invoice_id);
    }

    public function test_last_customer_receipt_cannot_be_archived_when_certificate_and_loading_files_remain(): void
    {
        [$delivery, $operator] = $this->shipment();
        $this->attachDeliveryEvidence($delivery, 'loading_photo');
        $this->attachDeliveryEvidence($delivery, 'coc');
        $receipt = $this->attachDeliveryEvidence($delivery);
        $delivery->forceFill(['status' => DeliveryStatus::Confirmed])->save();

        $response = $this->actingAs($operator)->getJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id)->assertOk();
        $receiptRow = collect($response->json('data.proofs'))->firstWhere('id', $receipt->hash_id);
        $this->assertFalse($receiptRow['can_archive']);
        $this->deleteJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/proofs/'.$receipt->hash_id)
            ->assertUnprocessable()->assertJsonPath('message', 'Cannot delete the only proof of a confirmed delivery.');

        $this->assertNull($receipt->fresh()->deleted_at);
        Storage::disk('local')->assertExists($receipt->file_path);
        $this->assertSame(3, $delivery->proofs()->count());
    }

    private function shipment(bool $reserve = true): array
    {
        $operator = User::factory()->withRole('impex_officer')->create(['is_active' => true]);
        $driver = User::factory()->withRole('driver')->create(['is_active' => true]);
        $vehicle = Vehicle::create([
            'plate_number' => 'PLAN-'.substr(uniqid(), -8), 'name' => 'Shipment plan vehicle',
            'vehicle_type' => 'van', 'status' => 'available',
        ]);
        [$delivery, $item, $location] = $this->deliveryWithApprovedStock($operator);
        $delivery->forceFill(['scheduled_date' => now()->toDateString()])->save();
        if (! $reserve) {
            $delivery->stockReservationBatch()->sole()->reservations()->delete();
            $delivery->stockReservationBatch()->delete();
            StockLevel::where('item_id', $item->id)->where('location_id', $location->id)->update(['reserved_quantity' => '0.000']);
        }

        return [$delivery->fresh(), $operator, $driver, $vehicle, $item, $location];
    }

    private function plan(User $driver, Vehicle $vehicle, bool $dispatch = true): array
    {
        return [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $vehicle->hash_id,
            'scheduled_date' => now()->toDateString(), 'expected_delivery_date' => now()->addDay()->toDateString(),
            'carrier' => 'Shipment test carrier', 'reason' => 'Set the vehicle, driver, and shipment dates.', 'dispatch' => $dispatch,
        ];
    }

    private function planUrl(Delivery $delivery): string
    {
        return '/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/shipment-plan';
    }

    private function statusUrl(Delivery $delivery): string
    {
        return '/api/v1/supply-chain/deliveries/'.$delivery->hash_id.'/status';
    }
}
