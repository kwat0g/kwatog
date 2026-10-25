<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Support\ProductionAssertions;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\DssRunnerService;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Creates one real, overdue production source record for an isolated DSS demo database. */
class DssDemoScenarioSeeder extends Seeder
{
    private const PRODUCT_NUMBER = 'DSS-DEMO-D07-001';

    private const CUSTOMER_EMAIL = 'dss-demo-customer@ogami.test';

    private const SALES_ORDER_NUMBER = 'SO-DSS-DEMO-001';

    private const WORK_ORDER_NUMBER = 'WO-DSS-DEMO-001';

    public function run(): void
    {
        ProductionAssertions::assertDemoSeedingAllowed();
        $this->assertDedicatedDatabase();

        $workOrder = DB::transaction(function (): WorkOrder {
            $actor = User::query()->where('email', 'dss-demo-actor@ogami.test')->first()
                ?? User::factory()->create([
                    'email' => 'dss-demo-actor@ogami.test',
                    'name' => 'DSS Demo Actor',
                    'password' => Str::random(64),
                    'must_change_password' => true,
                ]);
            $customer = Customer::query()->firstOrCreate(
                ['email' => self::CUSTOMER_EMAIL],
                ['name' => 'DSS Demo Customer', 'payment_terms_days' => 30, 'is_active' => true],
            );
            $product = Product::query()->firstOrCreate(
                ['part_number' => self::PRODUCT_NUMBER],
                ['name' => 'DSS Demo Finished Good', 'unit_of_measure' => 'pcs', 'standard_cost' => '10.00', 'is_active' => true],
            );
            $order = SalesOrder::query()->firstOrCreate(
                ['so_number' => self::SALES_ORDER_NUMBER],
                [
                    'customer_id' => $customer->id,
                    'date' => today(),
                    'subtotal' => '1000.00',
                    'vat_amount' => '120.00',
                    'total_amount' => '1120.00',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'payment_terms_days' => 30,
                    'created_by' => $actor->id,
                ],
            );
            if ((int) $order->customer_id !== (int) $customer->id) {
                throw new RuntimeException('DSS demo sales order marker belongs to a different customer.');
            }
            $line = SalesOrderItem::query()->firstOrCreate(
                ['sales_order_id' => $order->id, 'product_id' => $product->id],
                [
                    'quantity' => '100.00',
                    'unit_price' => '10.00',
                    'total' => '1000.00',
                    'quantity_delivered' => '0.00',
                    'delivery_date' => today()->addDays(14),
                ],
            );
            if ((int) $line->sales_order_id !== (int) $order->id || (int) $line->product_id !== (int) $product->id) {
                throw new RuntimeException('DSS demo sales-order line marker has conflicting links.');
            }

            $workOrder = WorkOrder::query()->firstOrCreate(
                ['wo_number' => self::WORK_ORDER_NUMBER],
                [
                    'product_id' => $product->id,
                    'sales_order_id' => $order->id,
                    'sales_order_item_id' => $line->id,
                    'quantity_target' => 100,
                    'quantity_produced' => 40,
                    'quantity_good' => 40,
                    'quantity_rejected' => 0,
                    'scrap_rate' => 0,
                    'planned_start' => now()->subDays(3),
                    'planned_end' => now()->subDay(),
                    'actual_start' => now()->subDays(3),
                    'status' => 'in_progress',
                    'priority' => 1,
                    'created_by' => $actor->id,
                ],
            );
            if ((int) $workOrder->product_id !== (int) $product->id
                || (int) $workOrder->sales_order_id !== (int) $order->id
                || (int) $workOrder->sales_order_item_id !== (int) $line->id) {
                throw new RuntimeException('DSS demo work-order marker has conflicting links.');
            }

            return $workOrder;
        });

        $outcome = app(DssRunnerService::class)->run('dss.work_order_intervention');
        $run = $outcome['detectors']['dss.work_order_intervention'] ?? null;
        $mode = app(DssParameterService::class)
            ->detectorMode('dss.work_order_intervention');

        $this->command?->info('D07 scenario ready: '.$workOrder->wo_number.' ('.$workOrder->hash_id.').');
        $this->command?->info('Detector run: '.($run['status'] ?? $outcome['engine']).'; mode: '.$mode.'.');
        $this->command?->comment('Shadow mode hides this card. Switch only this detector to advisory in this disposable database to view it in Decision Center.');
    }

    private function assertDedicatedDatabase(): void
    {
        $connection = config('database.connections.'.config('database.default'), []);
        $database = (string) ($connection['database'] ?? '');
        $host = strtolower(trim((string) ($connection['host'] ?? ''), '[]'));
        if (! empty($connection['url'])) {
            $url = parse_url((string) $connection['url']) ?: [];
            $database = rawurldecode(ltrim((string) ($url['path'] ?? ''), '/'));
            $host = strtolower(trim((string) ($url['host'] ?? ''), '[]'));
        }

        $dedicatedName = preg_match('/^ogami_(?:demo|test)(?:_[a-z0-9_]+)?$/iD', $database) === 1;
        $localHost = in_array($host, ['db', 'localhost', '127.0.0.1', '::1'], true);
        $memoryDatabase = ($connection['driver'] ?? null) === 'sqlite' && $database === ':memory:';
        if (! $memoryDatabase && (! $dedicatedName || ! $localHost)) {
            throw new RuntimeException('DSS demo scenario requires a dedicated local ogami_demo_* or ogami_test_* database.');
        }
    }
}
