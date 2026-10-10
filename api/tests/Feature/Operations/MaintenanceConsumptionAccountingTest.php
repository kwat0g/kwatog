<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Services\SparePartUsageService;
use App\Modules\MRP\Models\Machine;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MaintenanceConsumptionAccountingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_maintenance_consumption_posts_to_repairs_expense(): void
    {
        Event::fake();
        $this->seed(ChartOfAccountsSeeder::class);
        app(SettingsService::class)->set('modules.accounting', true);
        app(SettingsService::class)->set('accounting.accounts.maintenance_consumption_code', '6100');
        $actor = User::factory()->create();
        $machine = Machine::factory()->create();
        $order = MaintenanceWorkOrder::create([
            'mwo_number' => 'ACC-'.uniqid(), 'maintainable_type' => 'machine', 'maintainable_id' => $machine->id,
            'type' => 'corrective', 'priority' => 'high', 'description' => 'Repair', 'status' => 'in_progress', 'created_by' => $actor->id,
        ]);
        $item = Item::factory()->create(['item_type' => 'spare_part']);
        $location = WarehouseLocation::factory()->create();
        StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '10.000', 'weighted_avg_cost' => '5.0000']);
        $workRecord = MaintenanceLog::query()->create([
            'work_order_id' => $order->id, 'description' => 'Finding: Worn bearing. Action taken: Replaced bearing. Result: passed.',
            'finding' => 'Worn bearing.', 'action_taken' => 'Replaced bearing.', 'result' => 'passed',
            'logged_by' => $actor->id,
        ]);
        $usage = app(SparePartUsageService::class)->record($order, [
            'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '2',
            'operation_key' => 'c723c1ca-b69d-4944-ae46-904db5278b4b', 'work_record_id' => $workRecord->id,
        ], $actor);
        $entry = $usage->stockMovement->journal_entry_id;
        $this->assertNotNull($entry);
        $line = DB::table('journal_entry_lines')->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entry_id', $entry)->where('debit', '>', 0)->first(['accounts.code', 'debit']);
        $this->assertSame('6100', $line->code);
        $this->assertSame('10.00', $line->debit);
    }
}
