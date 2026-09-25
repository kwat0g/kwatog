<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O2C audit 2026-09-25 — the portal offered "Confirm Receipt" on every
 * delivered shipment, but confirmation requires a proof of delivery the
 * customer cannot upload, so the button answered with a 422. The detail now
 * says, from the server's own rule, whether the shipment can be confirmed.
 */
class CustomerPortalDeliveryConfirmableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    private function delivery(string $status, bool $withProof): array
    {
        $customer = Customer::factory()->create();
        $portalUser = CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'CustUser-'.substr(uniqid(), -5),
            'email' => 'cu-'.uniqid().'@t.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
        $internal = User::factory()->create();
        $delivery = Delivery::create([
            'delivery_number' => 'DR-T-'.substr(uniqid(), -5),
            'sales_order_id' => SalesOrder::factory()->create(['customer_id' => $customer->id, 'created_by' => $internal->id])->id,
            'scheduled_date' => now()->toDateString(),
            'status' => $status,
            'created_by' => $internal->id,
        ]);
        if ($withProof) {
            DeliveryProof::create([
                'delivery_id' => $delivery->id,
                'proof_type' => 'photo',
                'file_name' => 'receipt.jpg',
                'file_path' => 'deliveries/'.$delivery->id.'/receipt.jpg',
                'mime_type' => 'image/jpeg',
                'uploaded_by' => $internal->id,
            ]);
        }

        return [$portalUser, $delivery];
    }

    private function canConfirm(string $status, bool $withProof): mixed
    {
        [$portalUser, $delivery] = $this->delivery($status, $withProof);

        return $this->actingAs($portalUser, 'customer_portal')
            ->getJson("/api/v1/b2b/customer/deliveries/{$delivery->hash_id}")
            ->assertOk()
            ->json('data.can_confirm');
    }

    public function test_a_delivered_shipment_without_proof_is_not_yet_confirmable(): void
    {
        $this->assertFalse($this->canConfirm('delivered', false));
    }

    public function test_a_delivered_shipment_with_proof_is_confirmable(): void
    {
        $this->assertTrue($this->canConfirm('delivered', true));
    }

    public function test_shipments_not_yet_delivered_or_already_confirmed_are_not_confirmable(): void
    {
        $this->assertFalse($this->canConfirm('in_transit', true));
        $this->assertFalse($this->canConfirm('confirmed', true));
    }
    public function test_open_problem_report_explains_why_receipt_confirmation_is_held(): void
    {
        [$portalUser, $delivery] = $this->delivery('delivered', true);
        $case = new \App\Modules\ReturnManagement\Models\ReturnCase;
        $case->forceFill([
            'case_number' => 'CASE-HOLD-'.substr(uniqid(), -6), 'type' => 'customer', 'status' => 'submitted',
            'customer_id' => $portalUser->customer_id, 'delivery_id' => $delivery->id,
            'created_by' => $delivery->created_by, 'preferred_resolution' => 'credit', 'description' => 'One part missing',
        ])->save();
        $line = new \App\Modules\ReturnManagement\Models\ReturnCaseLine;
        $line->forceFill([
            'return_case_id' => $case->id, 'description' => 'Missing part', 'unit' => 'pcs',
            'expected_quantity' => '2.000', 'received_quantity' => '1.000', 'missing_quantity' => '1.000',
            'defective_quantity' => '0.000', 'source_unit_price' => '15.0000',
        ])->save();
        $this->actingAs($portalUser, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/deliveries/'.$delivery->hash_id)
            ->assertOk()->assertJsonPath('data.can_confirm', false)
            ->assertJsonPath('data.billing_hold.case_id', $case->hash_id);
        $case->forceFill(['status' => 'withdrawn'])->save();
        $this->getJson('/api/v1/b2b/customer/deliveries/'.$delivery->hash_id)
            ->assertOk()->assertJsonPath('data.can_confirm', true)->assertJsonPath('data.billing_hold', null);
    }

}
