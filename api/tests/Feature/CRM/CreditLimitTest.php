<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_so_confirm_blocked_when_credit_exceeded(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => 1000.00]);

        // Existing unpaid invoice = 900 — uses 'finalized' (the open AR status)
        Invoice::factory()->create([
            'customer_id'  => $customer->id,
            'total_amount' => 900.00,
            'amount_paid'  => 0.00,
            'balance'      => 900.00,
            'status'       => 'finalized',
        ]);

        // New SO = 200 — total exposure 1100 > 1000 limit
        $so = SalesOrder::factory()->create([
            'customer_id'  => $customer->id,
            'total_amount' => 200.00,
            'status'       => 'draft',
        ]);

        // Add at least one item so the confirm doesn't reject on empty items
        SalesOrderItem::factory()->create(['sales_order_id' => $so->id]);

        $this->actingAs($this->makeAdmin())
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('errors.credit_limit.0', fn ($v) => str_contains(strtolower($v), 'credit'));
    }

    public function test_so_confirm_passes_when_credit_limit_null(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => null]);

        $so = SalesOrder::factory()->create([
            'customer_id'  => $customer->id,
            'total_amount' => 200.00,
            'status'       => 'draft',
        ]);
        SalesOrderItem::factory()->create(['sales_order_id' => $so->id]);

        $this->actingAs($this->makeAdmin())
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")
            ->assertStatus(200);
    }

    public function test_so_confirm_passes_when_credit_limit_zero(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '0.00']);
        $so = SalesOrder::factory()->create([
            'customer_id'  => $customer->id,
            'total_amount' => 200.00,
            'status'       => 'draft',
        ]);
        SalesOrderItem::factory()->create(['sales_order_id' => $so->id]);

        $this->actingAs($this->makeAdmin())
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")
            ->assertStatus(200);
    }
}
