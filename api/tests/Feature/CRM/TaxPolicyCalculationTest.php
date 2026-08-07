<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\PricingMethod;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\SalesOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VAT must come from the persisted `tax.ph.vat_rate` setting, never a hardcoded
 * 0.12 — the rate is a policy value an admin can change at runtime.
 *
 * Previously asserted through QuoteService; rehomed to SalesOrderService when the
 * CRM sales funnel was cut. Both read the same TaxPolicyService, and the sales
 * order is the document that actually carries VAT into Chain 1.
 */
class TaxPolicyCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_sales_order_uses_persisted_vat_rate(): void
    {
        app(SettingsService::class)->set('tax.ph.vat_rate', 0.15, 'tax');

        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $user = User::factory()->create();

        PriceAgreement::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => 100,
            'pricing_method' => PricingMethod::Flat,
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => now()->addMonth()->toDateString(),
        ]);

        $so = app(SalesOrderService::class)->create([
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'delivery_date' => now()->addWeek()->toDateString(),
            ]],
        ], $user->id);

        // 2 × ₱100 = ₱200 subtotal; 15% VAT = ₱30 (not the ₱24 a hardcoded 12% gives).
        $this->assertSame('200.00', (string) $so->subtotal);
        $this->assertSame('30.00', (string) $so->vat_amount);
        $this->assertSame('230.00', (string) $so->total_amount);
    }
}
