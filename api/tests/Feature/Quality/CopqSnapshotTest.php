<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\CopqSnapshot;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\CopqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopqSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_scrap_uses_product_standard_cost(): void
    {
        $month = now()->startOfMonth();
        $product = Product::factory()->create(['standard_cost' => 25.00]);

        NonConformanceReport::factory()->create([
            'status'            => 'closed',
            'disposition'       => 'scrap',
            'product_id'        => $product->id,
            'affected_quantity' => 10,
            'closed_at'         => $month->copy()->addDay(),
        ]);

        $snap = app(CopqService::class)->snapshot($month->year, $month->month);

        $this->assertInstanceOf(CopqSnapshot::class, $snap);
        $this->assertEquals('250.00', $snap->internal_scrap_cost);   // 10 * 25.00
        $this->assertEquals('250.00', $snap->total_cost);
    }

    public function test_rework_costs_thirty_percent_of_product_cost(): void
    {
        $month = now()->startOfMonth();
        $product = Product::factory()->create(['standard_cost' => 50.00]);

        $ncr = NonConformanceReport::factory()->create([
            'status'      => 'open',
            'disposition' => 'rework',
            'product_id'  => $product->id,
        ]);
        WorkOrder::factory()->create([
            'parent_ncr_id'   => $ncr->id,
            'product_id'      => $product->id,
            'quantity_target' => 20,
            'created_at'      => $month->copy()->addDay(),
        ]);

        $snap = app(CopqService::class)->snapshot($month->year, $month->month);

        // 20 * 50.00 * 0.30 = 300.00
        $this->assertEquals('300.00', $snap->internal_rework_cost);
        $this->assertEquals('300.00', $snap->total_cost);
    }

    public function test_returns_and_complaints_are_counted_in_breakdown(): void
    {
        $month = now()->startOfMonth();

        $customer = Customer::factory()->create();
        $user     = User::factory()->create();

        \DB::table('return_requests')->insert([
            'rma_number'   => 'RMA-T-001',
            'type'         => 'customer_return',
            'status'       => 'completed',
            'reason_code'  => 'defective',
            'created_at'   => $month->copy()->addDay(),
            'updated_at'   => $month->copy()->addDay(),
        ]);
        \DB::table('customer_complaints')->insert([
            'complaint_number' => 'CMP-T-001',
            'customer_id'      => $customer->id,
            'received_date'    => $month->copy()->addDay()->toDateString(),
            'severity'         => 'low',
            'status'           => 'open',
            'description'      => 'test complaint',
            'affected_quantity' => 0,
            'created_by'       => $user->id,
            'created_at'       => $month->copy()->addDay(),
            'updated_at'       => $month->copy()->addDay(),
        ]);

        $snap = app(CopqService::class)->snapshot($month->year, $month->month);

        $this->assertSame(1, $snap->breakdown['external_failure']['returns']);
        $this->assertSame(1, $snap->breakdown['external_failure']['complaints']);
    }

    public function test_no_data_writes_zero_snapshot(): void
    {
        $month = now()->startOfMonth();

        $snap = app(CopqService::class)->snapshot($month->year, $month->month);

        $this->assertEquals('0.00', $snap->total_cost);
        $this->assertEquals('0.00', $snap->internal_scrap_cost);
        $this->assertEquals('0.00', $snap->internal_rework_cost);
    }
}
