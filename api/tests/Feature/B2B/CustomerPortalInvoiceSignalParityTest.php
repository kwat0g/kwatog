<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\B2B\Models\CustomerPortalUser;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerPortalInvoiceSignalParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_invoice_badges_and_filters_match_with_customer_scope(): void
    {
        $customer = Customer::factory()->create();
        $portal = $this->portalUser($customer);
        $otherCustomer = Customer::factory()->create();
        $otherPortal = $this->portalUser($otherCustomer);

        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'balance' => '100.00',
            'amount_paid' => '0.00',
            'due_date' => today('Asia/Manila')->subDay(),
        ]);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'partial',
            'balance' => '50.00',
            'amount_paid' => '50.00',
            'due_date' => today('Asia/Manila'),
        ]);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'balance' => '0.00',
            'amount_paid' => '100.00',
            'due_date' => today('Asia/Manila')->subDay(),
        ]);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paid',
            'balance' => '0.00',
            'amount_paid' => '100.00',
            'due_date' => today('Asia/Manila')->subDay(),
        ]);
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'cancelled',
            'balance' => '20.00',
            'amount_paid' => '0.00',
            'due_date' => today('Asia/Manila')->subDay(),
        ]);
        Invoice::factory()->create([
            'customer_id' => $otherCustomer->id,
            'status' => 'finalized',
            'balance' => '200.00',
            'amount_paid' => '0.00',
            'due_date' => today('Asia/Manila')->subDay(),
        ]);

        foreach ([['unpaid', 2], ['overdue', 1]] as [$signal, $expected]) {
            $list = $this->actingAs($portal, 'customer_portal')
                ->getJson('/api/v1/b2b/customer/invoices?signal='.$signal)
                ->assertOk();
            $this->assertSame($expected, $list->json('meta.total'));
        }

        $this->actingAs($portal, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/badges')
            ->assertOk()
            ->assertJsonPath('data.unpaid', 2)
            ->assertJsonPath('data.overdue', 1);
        $this->actingAs($otherPortal, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/badges')
            ->assertOk()
            ->assertJsonPath('data.unpaid', 1)
            ->assertJsonPath('data.overdue', 1);
        $this->actingAs($portal, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/dashboard')
            ->assertOk()
            ->assertJsonPath('data.open_invoice_count', 2)
            ->assertJsonPath('data.total_outstanding', '150.00');
        $this->actingAs($portal, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/invoices?signal=invalid')
            ->assertUnprocessable();
    }

    private function portalUser(Customer $customer): CustomerPortalUser
    {
        return CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Invoice signal customer',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }
}
