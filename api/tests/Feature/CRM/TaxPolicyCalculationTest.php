<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxPolicyCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_uses_persisted_vat_rate(): void
    {
        app(SettingsService::class)->set('tax.ph.vat_rate', 0.15, 'tax');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();

        $quote = app(QuoteService::class)->create([
            'customer_id' => $customer->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 100,
            ]],
        ]);

        $this->assertSame('200.00', (string) $quote->subtotal);
        $this->assertSame('30.00', (string) $quote->tax_amount);
        $this->assertSame('230.00', (string) $quote->total_amount);
    }
}
