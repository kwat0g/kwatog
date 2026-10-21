<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Services\DeliveryReceiptService;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\HasDeliveryStock;
use Tests\TestCase;

/**
 * Chain 1 — the driver has to know where the goods go.
 *
 * Covers the customer master's ship-to fields, the delivery snapshot taken from
 * them, the one fallback rule every surface reads, the driver run sheet payload
 * and the printable delivery receipt.
 */
class DeliveryShipToAddressTest extends TestCase
{
    use HasDeliveryStock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    private function staff(string $role = 'impex_officer'): User
    {
        return User::factory()->withRole($role)->create(['is_active' => true]);
    }

    private function driver(): User
    {
        return User::factory()->withRole('driver')->create(['is_active' => true]);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes);
    }

    /** Pre-dispatch assignment is the act of arranging the run. */
    private function assign(Delivery $delivery, User $actor): Delivery
    {
        $vehicle = Vehicle::create([
            'plate_number' => 'SHIP-'.substr(uniqid(), -8),
            'name' => 'Ship-to test vehicle',
            'vehicle_type' => 'van',
            'capacity_kg' => '1000.00',
            'status' => 'available',
        ]);

        return app(DeliveryService::class)->assign($delivery, [
            'vehicle_id' => $vehicle->id,
            'driver_id' => $this->driver()->id,
            'reason' => 'Ship-to test assignment.',
        ], $actor);
    }

    public function test_customer_delivery_address_is_saved_and_returned(): void
    {
        // Customer master-data creation is the sales side's job; Finance only
        // reads customers (see RolePermissionSeeder's accounting module except-list).
        $actor = $this->staff('customer_service_officer');

        $created = $this->actingAs($actor)->postJson('/api/v1/crm/customers', [
            'name' => 'Toyota Motor Philippines',
            'delivery_address' => 'Toyota Plant, Block 17, SEPZ, Santa Rosa, Laguna',
            'delivery_contact_person' => 'Ana Reyes',
            'delivery_contact_phone' => '049-500-1234',
        ])->assertCreated()
            ->assertJsonPath('data.delivery_address', 'Toyota Plant, Block 17, SEPZ, Santa Rosa, Laguna')
            ->assertJsonPath('data.delivery_contact_person', 'Ana Reyes')
            ->assertJsonPath('data.delivery_contact_phone', '049-500-1234')
            ->json('data');

        $this->actingAs($actor)
            ->putJson("/api/v1/crm/customers/{$created['id']}", ['delivery_address' => 'Gate 3, Santa Rosa'])
            ->assertOk()
            ->assertJsonPath('data.delivery_address', 'Gate 3, Santa Rosa');

        $this->assertSame('Gate 3, Santa Rosa', Customer::query()->latest('id')->firstOrFail()->delivery_address);
    }

    public function test_the_draft_copies_the_customer_ship_to_onto_the_run_sheet(): void
    {
        $actor = $this->staff();
        $customer = $this->customer([
            'address' => 'Billing address, Makati',
            'delivery_address' => 'Toyota Plant, Santa Rosa, Laguna',
            'delivery_contact_person' => 'Ana Reyes',
            'delivery_contact_phone' => '049-500-1234',
        ]);

        [$delivery] = $this->deliveryWithApprovedStock($actor, $customer);
        $fresh = $delivery->fresh();

        $this->assertSame('Toyota Plant, Santa Rosa, Laguna', $fresh->delivery_address);
        $this->assertSame('Ana Reyes', $fresh->delivery_contact_person);
        $this->assertSame('049-500-1234', $fresh->delivery_contact_phone);
        // The billing address is NOT what the snapshot takes.
        $this->assertNotSame('Billing address, Makati', $fresh->delivery_address);
    }

    public function test_the_ship_to_falls_back_to_the_customer_master_then_the_billing_address(): void
    {
        $actor = $this->staff();
        $customer = $this->customer(['address' => 'Billing only address']);

        [$delivery] = $this->deliveryWithApprovedStock($actor, $customer);
        $fresh = $delivery->fresh();

        $this->assertNull($fresh->delivery_address);
        $this->assertSame('Billing only address', $fresh->resolvedDeliveryAddress());

        $customer->update(['delivery_address' => 'Ship-to address']);
        $this->assertSame('Ship-to address', $fresh->fresh()->resolvedDeliveryAddress());

        $customer->update(['delivery_address' => null, 'address' => null]);
        $this->assertNull($fresh->fresh()->resolvedDeliveryAddress());
    }

    public function test_a_customer_edit_does_not_rewrite_an_issued_run_sheet(): void
    {
        $actor = $this->staff();
        $customer = $this->customer(['delivery_address' => 'Original gate']);

        [$delivery] = $this->deliveryWithApprovedStock($actor, $customer);
        $this->assign($delivery->fresh(), $actor);

        $customer->update(['delivery_address' => 'Somewhere else entirely']);

        $fresh = $delivery->fresh();
        $this->assertSame('Original gate', $fresh->delivery_address);
        $this->assertSame('Original gate', $fresh->resolvedDeliveryAddress());
    }

    public function test_reassigning_before_dispatch_picks_up_a_corrected_address(): void
    {
        $actor = $this->staff();
        $customer = $this->customer(['delivery_address' => 'Gate 1 (wrong gate)']);

        [$delivery] = $this->deliveryWithApprovedStock($actor, $customer);
        $customer->update(['delivery_address' => 'Gate 3 (receiving)']);

        $updated = $this->assign($delivery->fresh(), $actor);

        $this->assertSame('Gate 3 (receiving)', $updated->delivery_address);
        $this->assertSame('Gate 3 (receiving)', $delivery->fresh()->resolvedDeliveryAddress());
    }

    public function test_the_driver_run_sheet_shows_where_to_deliver(): void
    {
        $actor = $this->staff();
        $customer = $this->customer([
            'delivery_address' => 'Toyota Plant, Santa Rosa, Laguna',
            'delivery_contact_person' => 'Ana Reyes',
            'delivery_contact_phone' => '049-500-1234',
        ]);

        [$delivery] = $this->deliveryWithApprovedStock($actor, $customer);
        $driver = $this->driver();
        $delivery->forceFill(['driver_id' => $driver->id])->save();

        $this->actingAs($driver)
            ->getJson("/api/v1/driver/deliveries/{$delivery->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.delivery_address', 'Toyota Plant, Santa Rosa, Laguna')
            ->assertJsonPath('data.delivery_contact_person', 'Ana Reyes')
            ->assertJsonPath('data.delivery_contact_phone', '049-500-1234');
    }

    public function test_the_delivery_receipt_pdf_renders(): void
    {
        $actor = $this->staff('warehouse_staff');
        $customer = $this->customer([
            'delivery_address' => 'Toyota Plant, Santa Rosa, Laguna',
            'delivery_contact_person' => 'Ana Reyes',
        ]);

        [$delivery] = $this->deliveryWithApprovedStock($actor, $customer);

        $response = $this->actingAs($actor)
            ->get("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/delivery-receipt");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $bytes = $response->streamedContent();
        $this->assertStringStartsWith('%PDF', $bytes);

        // The receipt is vaulted like every other generated document, so the
        // audit trail knows a run sheet was printed and by whom.
        $this->assertDatabaseHas('documents', [
            'document_type' => 'delivery_receipt',
            'entity_id' => $delivery->id,
        ]);

        // A PDF is compressed bytes, so "the file rendered" would keep passing
        // if the address silently stopped reaching the template. Assert the
        // sheet as HTML, using the service's own view data — that also proves
        // the service's wiring, not just the Blade's.
        $data = app(DeliveryReceiptService::class)->viewData(Delivery::query()->findOrFail($delivery->id));
        $html = view('pdf.delivery-receipt', $data + [
            'company' => [
                'name' => 'Philippine Ogami Corporation',
                'address' => 'FCIE Dasmariñas, Cavite',
                'phone' => '', 'email' => '', 'public_url' => '', 'tin' => '',
                'vat_status' => '', 'certification' => '', 'tagline' => '',
                'logo_data_uri' => '', 'accent' => '#b4542a',
            ],
            'generated' => ['by' => 'Test', 'at_text' => 'Oct 20, 2026'],
            'docTitle' => 'Delivery Receipt',
            'confidential' => false,
            'watermark' => null,
        ])->render();

        $this->assertStringContainsString('Deliver to', $html);
        $this->assertStringContainsString('Toyota Plant, Santa Rosa, Laguna', $html);
        $this->assertStringContainsString('Ana Reyes', $html);
        $this->assertStringContainsString($delivery->delivery_number, $html);
    }

    public function test_the_delivery_receipt_requires_the_delivery_read_permission(): void
    {
        $actor = $this->staff();
        $customer = $this->customer(['delivery_address' => 'Toyota Plant, Santa Rosa, Laguna']);
        [$delivery] = $this->deliveryWithApprovedStock($actor, $customer);

        $outsider = $this->staff('employee');

        $this->actingAs($outsider)
            ->getJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/delivery-receipt")
            ->assertForbidden();
    }
}
