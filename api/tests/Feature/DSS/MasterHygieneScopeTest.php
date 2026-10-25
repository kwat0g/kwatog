<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use App\Modules\DSS\Support\MasterHygieneScope;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterHygieneScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_gap_uses_the_exact_live_source_predicate(): void
    {
        $complete = ['reorder_point' => 10, 'safety_stock' => 5, 'lead_time_days' => 7, 'standard_cost' => 100];
        Item::factory()->create([...$complete, 'reorder_point' => 0]);
        Item::factory()->create([...$complete, 'safety_stock' => 0]);
        Item::factory()->create([...$complete, 'lead_time_days' => 0]);
        Item::factory()->create([...$complete, 'standard_cost' => 0]);
        Customer::factory()->create(['credit_limit' => null]);
        Product::factory()->create(['is_active' => true]);

        $scope = app(MasterHygieneScope::class);

        foreach ([
            'items_missing_reorder_point' => 1,
            'items_missing_safety_stock' => 0,
            'items_missing_lead_time' => 1,
            'items_missing_standard_cost' => 1,
            'items_without_approved_supplier' => 4,
            'customers_missing_credit_limit' => 1,
            'products_without_active_bom' => 1,
        ] as $gap => $expected) {
            $this->assertSame($expected, $scope->query($gap)->count(), $gap);
        }
    }

    public function test_unknown_gap_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(MasterHygieneScope::class)->query('untrusted_gap');
    }
}
