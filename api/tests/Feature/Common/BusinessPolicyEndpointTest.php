<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Services\CustomerService;
use App\Modules\Accounting\Services\VendorService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessPolicyEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_and_master_data_creation_use_persisted_defaults(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('sales.default_customer_payment_terms_days', 45, 'sales');
        $settings->set('purchasing.default_vendor_payment_terms_days', 60, 'purchasing');
        $settings->set('sales.default_delivery_lead_days', 21, 'sales');
        $settings->set('mrp.default_lead_time_days', 18, 'mrp');
        $settings->set('approval.po.vp_threshold', 75000, 'approval');

        $customer = app(CustomerService::class)->create(['name' => 'Policy Customer']);
        $vendor = app(VendorService::class)->create(['name' => 'Policy Vendor']);

        $this->assertSame(45, $customer->payment_terms_days);
        $this->assertSame(60, $vendor->payment_terms_days);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/business-policies')
            ->assertOk()
            ->assertJsonPath('data.customer_payment_terms_days', 45)
            ->assertJsonPath('data.vendor_payment_terms_days', 60)
            ->assertJsonPath('data.sales_delivery_lead_days', 21)
            ->assertJsonPath('data.mrp_default_lead_time_days', 18)
            ->assertJsonPath('data.purchase_order_vp_threshold', 75000)
            ->assertJsonPath('data.vat_rate', '0.12');
    }
}
