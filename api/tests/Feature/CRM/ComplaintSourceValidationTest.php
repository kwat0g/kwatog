<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Services\ComplaintService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class ComplaintSourceValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_create_rejects_an_order_from_another_customer(): void
    {
        $customer = Customer::factory()->create();
        $otherOrder = SalesOrder::factory()->create();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('does not belong to the selected customer');

        app(ComplaintService::class)->create($this->payload([
            'customer_id' => $customer->id,
            'sales_order_id' => $otherOrder->id,
        ]), User::factory()->create());

        $this->assertSame(0, CustomerComplaint::query()->count());
    }

    public function test_create_rejects_a_product_not_present_on_the_order(): void
    {
        $customer = Customer::factory()->create();
        $order = SalesOrder::factory()->create(['customer_id' => $customer->id]);
        $product = Product::factory()->create();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not part of the selected sales order');

        app(ComplaintService::class)->create($this->payload([
            'customer_id' => $customer->id,
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]), User::factory()->create());
    }

    public function test_create_rejects_an_inactive_assignee(): void
    {
        $assignee = User::factory()->create(['is_active' => false]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('inactive or not authorized');

        app(ComplaintService::class)->create($this->payload([
            'assigned_to' => $assignee->id,
        ]), User::factory()->create());
    }

    public function test_http_intake_returns_422_for_mismatched_sales_order(): void
    {
        $customer = Customer::factory()->create();
        $order = SalesOrder::factory()->create();
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/crm/complaints', [
                'customer_id' => $customer->hash_id,
                'sales_order_id' => $order->hash_id,
                'received_date' => today()->toDateString(),
                'severity' => 'medium',
                'description' => 'Mismatched source-chain request',
                'affected_quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sales_order_id']);
    }

    public function test_http_intake_rejects_an_undecodable_customer_hash(): void
    {
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/crm/complaints', [
                'customer_id' => 'not-a-valid-hash',
                'received_date' => today()->toDateString(),
                'severity' => 'medium',
                'description' => 'Invalid source identifier request',
                'affected_quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_customer_force_delete_cannot_remove_complaint_history(): void
    {
        $customer = Customer::factory()->create();
        $creator = User::factory()->create();
        CustomerComplaint::create([
            'complaint_number' => 'CC-RETENTION-'.substr(uniqid(), -6),
            'customer_id' => $customer->id,
            'received_date' => today(),
            'severity' => 'medium',
            'status' => 'open',
            'description' => 'Retention guard test',
            'affected_quantity' => 1,
            'created_by' => $creator->id,
        ]);

        try {
            $customer->forceDelete();
            $this->fail('A customer with complaint history must not be physically deleted.');
        } catch (QueryException) {
            // The complaint foreign key is intentionally RESTRICT, preserving
            // the quality record until an explicit retention workflow exists.
        }

        $this->assertDatabaseHas('customer_complaints', [
            'customer_id' => $customer->id,
            'description' => 'Retention guard test',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'received_date' => now()->toDateString(),
            'severity' => 'medium',
            'description' => 'Source-chain validation test',
            'affected_quantity' => 1,
        ], $overrides);
    }
}
