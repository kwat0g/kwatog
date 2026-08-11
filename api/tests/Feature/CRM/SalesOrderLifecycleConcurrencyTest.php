<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private SalesOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalesOrderService::class);
    }

    public function test_confirmation_rechecks_a_stale_sales_order_before_running_mrp(): void
    {
        $so = $this->makeSalesOrder(SalesOrderStatus::Draft);
        $stale = $so->fresh();

        $so->forceFill(['status' => SalesOrderStatus::Cancelled])->save();

        try {
            $this->service->confirm($stale);
            $this->fail('A stale confirmation must not resurrect a cancelled sales order.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Only draft sales orders can be confirmed.', $e->getMessage());
        }

        $this->assertSame(SalesOrderStatus::Cancelled, $so->fresh()->status);
        $this->assertDatabaseCount('mrp_plans', 0);
    }

    public function test_cancellation_rechecks_a_stale_sales_order_before_overwriting_terminal_state(): void
    {
        $so = $this->makeSalesOrder(SalesOrderStatus::Draft);
        $stale = $so->fresh();

        $so->forceFill(['status' => SalesOrderStatus::Invoiced])->save();

        try {
            $this->service->cancel($stale, 'stale operator request');
            $this->fail('A stale cancellation must not overwrite an invoiced sales order.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('This sales order cannot be cancelled at its current status.', $e->getMessage());
        }

        $this->assertSame(SalesOrderStatus::Invoiced, $so->fresh()->status);
    }

    private function makeSalesOrder(SalesOrderStatus $status): SalesOrder
    {
        $so = SalesOrder::factory()->create([
            'status' => $status->value,
            'total_amount' => '100.00',
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
        ]);

        SalesOrderItem::query()->create([
            'sales_order_id' => $so->id,
            'product_id' => \App\Modules\CRM\Models\Product::factory()->create()->id,
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'total' => '100.00',
            'quantity_delivered' => '0.00',
            'delivery_date' => now()->addDay()->toDateString(),
        ]);

        return $so->fresh();
    }
}
