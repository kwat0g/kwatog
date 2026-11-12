<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Models\CustomerProductWarrantyOption;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderAdvanceApplication;
use App\Modules\CRM\Services\SalesOrderAdvanceService;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cross-module regression for the customer portal order, deposit, invoice, and
 * feedback contracts. Physical dispatch and agreement signing are covered by
 * their dedicated O2C and contract lifecycle suites.
 */
class PortalOrderAgreementDeliveryFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
        app(SettingsService::class)->set('company.vat_status', 'Non-VAT', 'company');
        Storage::fake('local');
    }

    public function test_customer_order_deposit_delivery_feedback_and_invoice_application_remain_linked(): void
    {
        $customer = Customer::factory()->create(['payment_terms_days' => 45]);
        $portal = CustomerPortalUser::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Portal Buyer',
            'email' => Str::uuid().'@example.test',
            'password' => 'Password1!',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $sales = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        $finance = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'base_selling_price' => '100.00',
            'portal_visibility' => 'restricted',
        ]);
        $product->portalCustomers()->attach($customer->id);
        PriceAgreement::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '100.00',
            'effective_from' => today()->subDay()->toDateString(),
            'effective_to' => today()->addYear()->toDateString(),
            'pricing_method' => PricingMethod::Flat->value,
        ]);
        $warranty = CustomerProductWarrantyOption::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'label' => '12 months from delivery',
            'warranty_duration_days' => 365,
            'warranty_report_window_days' => 30,
            'warranty_start_basis' => 'delivery',
            'warranty_covered_issues' => ['manufacturing_defect'],
            'warranty_remedies' => ['repair'],
            'position' => 1,
        ]);

        $this->actingAs($portal, 'customer_portal')
            ->postJson('/api/v1/b2b/customer/orders', [
                'payment_method' => 'bank_transfer',
                'items' => [[
                    'product_id' => $product->hash_id,
                    'quantity' => 1,
                    'delivery_date' => today()->addDays(7)->toDateString(),
                    'warranty_option_id' => $warranty->hash_id,
                ]],
            ], ['Idempotency-Key' => 'portal-flow-'.Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.payment_terms_days', 45);

        $order = SalesOrder::query()->where('customer_id', $customer->id)->sole();
        $line = $order->items()->sole();
        $this->assertSame('12 months from delivery', $line->warranty_terms_snapshot['warranty_option_label']);
        $this->assertSame(365, $line->warranty_terms_snapshot['warranty_duration_days']);

        $advanceService = app(SalesOrderAdvanceService::class);
        $advance = $advanceService->configure($order, '25.00', null, $sales);
        $this->assertNotNull($advance);
        $order->update(['status' => 'confirmed']);
        $this->assertFalse($advanceService->releaseProductionIfReady($order));
        $this->assertNull($order->fresh()->mrp_released_at);

        $this->actingAs($portal, 'customer_portal')
            ->post('/api/v1/b2b/customer/orders/'.$order->hash_id.'/advance-evidence', [
                'amount' => '25.00',
                'sending_bank' => 'BDO',
                'reference_number' => 'PORTAL-FLOW-'.Str::uuid(),
                'payment_date' => today('Asia/Manila')->toDateString(),
                'idempotency_key' => (string) Str::uuid(),
                'proof_file' => UploadedFile::fake()->image('bank-proof.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
        $advance->refresh();

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/crm/sales-order-advances/'.$advance->hash_id.'/verify', [
                'bank_account_id' => Account::query()->where('code', '1020')->firstOrFail()->hash_id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');
        $this->assertNotNull($order->fresh()->mrp_released_at);
        $this->assertFalse($advanceService->releaseProductionIfReady($order->fresh()));

        Delivery::query()->create([
            'delivery_number' => 'PF-'.Str::upper(Str::random(10)),
            'sales_order_id' => $order->id,
            'status' => 'confirmed',
            'scheduled_date' => today()->toDateString(),
            'delivered_at' => now(),
            'created_by' => $sales->id,
        ]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'sales_order_id' => $order->id,
            'status' => 'finalized',
            'date' => today('Asia/Manila')->toDateString(),
            'total_amount' => '100.00',
            'amount_paid' => '0.00',
            'balance' => '100.00',
        ]);

        $applications = $advanceService->applyToInvoice($order, $invoice, $sales);
        $this->assertCount(1, $applications);
        $this->assertSame('25.00', (string) $applications[0]->amount);
        $this->assertSame('75.00', (string) $invoice->fresh()->balance);
        $this->assertSame([], $advanceService->applyToInvoice($order, $invoice->fresh(), $sales));
        $this->assertSame(1, SalesOrderAdvanceApplication::query()->where('invoice_id', $invoice->id)->count());

        $this->actingAs($portal, 'customer_portal')
            ->postJson('/api/v1/b2b/customer/orders/'.$order->hash_id.'/feedback', [
                'rating' => 5,
                'comment' => 'Order arrived as agreed.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.submitted', true)
            ->assertJsonMissingPath('data.rating');

        $this->actingAs($portal, 'customer_portal')
            ->getJson('/api/v1/b2b/customer/orders/'.$order->hash_id)
            ->assertOk()
            ->assertJsonPath('data.customer_feedback.submitted', true)
            ->assertJsonMissingPath('data.customer_feedback.comment')
            ->assertDontSee('Order arrived as agreed.');

        $this->actingAs($sales, 'sanctum')
            ->getJson('/api/v1/crm/sales-orders/'.$order->hash_id)
            ->assertOk()
            ->assertJsonPath('data.customer_feedback.rating', 5)
            ->assertJsonPath('data.customer_feedback.comment', 'Order arrived as agreed.');
    }
}
