<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Resources\SalesOrderResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesOrderResourceProgressTest extends TestCase
{
    #[DataProvider('coverage')]
    public function test_display_status_uses_delivered_quantities_without_mutating_the_order(string $status, array $quantities, string $expected): void
    {
        $order = new SalesOrder(['status' => $status, 'submission_source' => 'customer_portal']);
        $order->setAttribute('id', 1);
        $order->setRelation('items', new Collection(array_map(
            static fn (array $quantity): SalesOrderItem => new SalesOrderItem(['quantity' => $quantity[0], 'quantity_delivered' => $quantity[1]]),
            $quantities,
        )));
        $before = $order->getAttributes();
        $snapshot = (new SalesOrderResource($order))->toArray(Request::create('/'));
        $this->assertSame($expected, $snapshot['status']);
        $this->assertSame(SalesOrderStatus::from($expected)->label(), $snapshot['status_label']);
        $this->assertSame($before, $order->getAttributes());
    }

    public static function coverage(): array
    {
        return [
            'complete delivery' => ['in_production', [['500.00', '500.00']], 'delivered'],
            'partial delivery' => ['in_production', [['500.00', '498.00']], 'partially_delivered'],
            'other line still owed' => ['confirmed', [['500.00', '500.00'], ['2.00', '0.00']], 'partially_delivered'],
            'nothing delivered' => ['in_production', [['500.00', '0.00']], 'in_production'],
            'decimal remainder' => ['partially_delivered', [['0.02', '0.01']], 'partially_delivered'],
            'cancelled stays cancelled' => ['cancelled', [['500.00', '500.00']], 'cancelled'],
            'invoiced stays invoiced' => ['invoiced', [['500.00', '500.00']], 'invoiced'],
            'paid stays paid' => ['paid', [['500.00', '500.00']], 'paid'],
            'closed stays closed' => ['closed', [['500.00', '500.00']], 'closed'],
            'draft stays draft' => ['draft', [['500.00', '500.00']], 'draft'],
        ];
    }
}
