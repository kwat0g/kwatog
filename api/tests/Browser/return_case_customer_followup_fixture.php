<?php

declare(strict_types=1);

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\SupplyChain\Models\Delivery;

// Add a separate customer shipment for the Customer Service disposition check.
if (! str_starts_with(config('database.connections.pgsql.database'), 'ogami_test_return_browser_')) {
    throw new RuntimeException('This fixture requires its dedicated browser-test database.');
}
$fixture = json_decode(file_get_contents('/tmp/return-case-role-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$original = Delivery::findOrFail(Delivery::tryDecodeHash($fixture['delivery']));
$source = $original->items()->with('salesOrderItem')->firstOrFail()->salesOrderItem;
$order = SalesOrder::factory()->create(['customer_id' => $original->salesOrder->customer_id, 'created_by' => $original->created_by, 'status' => 'partially_delivered']);
$line = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $source->product_id, 'quantity' => '100', 'quantity_delivered' => '100', 'unit_price' => '25']);
$delivery = Delivery::create(['delivery_number' => 'DEL-RETURN-CS-HANDOFF', 'sales_order_id' => $order->id, 'status' => 'delivered', 'scheduled_date' => now()->toDateString(), 'delivered_at' => now(), 'created_by' => $original->created_by]);
$delivery->items()->create(['sales_order_item_id' => $line->id, 'quantity' => '100', 'unit_price' => '25']);
$fixture['delivery'] = $delivery->hash_id;
file_put_contents('/tmp/return-case-customer-role-fixture.json', json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Separate customer shipment ready.\n";
