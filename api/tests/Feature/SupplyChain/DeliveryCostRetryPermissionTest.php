<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryCostHandoff;
use App\Modules\SupplyChain\Resources\DeliveryResource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryCostRetryPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_finance_can_reach_delivery_cost_retry_validation(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $delivery = $this->delivery();

        $this->actingAs($finance)
            ->postJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/retry-cost-handoff", [
                'kind' => 'customer_cogs',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_key');
    }

    public function test_finance_sees_retry_for_a_manual_cost_handoff(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $delivery = $this->delivery();
        DeliveryCostHandoff::create([
            'delivery_id' => $delivery->id,
            'request_key' => (string) Str::uuid(),
            'payload_fingerprint' => str_repeat('a', 64),
            'handoff_type' => 'customer_cogs',
            'status' => 'manual_required',
            'target_amount' => '12.00',
            'delta_amount' => '12.00',
            'created_by' => $finance->id,
            'attempted_at' => now(),
        ]);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $finance);
        $resource = (new DeliveryResource($delivery->fresh()))->resolve($request);
        $this->assertTrue($resource['cogs_handoff']['can_retry']);
    }

    public function test_employee_cannot_reach_delivery_cost_retry_validation(): void
    {
        $employee = $this->userWithRole('employee');
        $delivery = $this->delivery();

        $this->actingAs($employee)
            ->postJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/retry-cost-handoff", [])
            ->assertForbidden();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    private function delivery(): Delivery
    {
        $creator = User::factory()->create();
        $order = SalesOrder::factory()->create();

        return Delivery::create([
            'delivery_number' => 'DL-'.strtoupper(Str::random(10)),
            'sales_order_id' => $order->id,
            'status' => 'confirmed',
            'scheduled_date' => now()->toDateString(),
            'created_by' => $creator->id,
            'cost_recognition_mode' => 'transit',
        ]);
    }
}
