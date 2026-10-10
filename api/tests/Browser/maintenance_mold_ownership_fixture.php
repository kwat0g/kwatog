<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

$tag = getenv('E2E_MAINTENANCE_TAG');
if (! $tag || ! preg_match('/^[a-z0-9-]{1,6}$/D', $tag)) {
    throw new RuntimeException('A unique E2E_MAINTENANCE_TAG is required.');
}
if (app()->environment() !== 'local' || DB::connection()->getDatabaseName() !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('Refusing maintenance ownership fixtures outside the local audit database.');
}

$fixture = DB::transaction(function () use ($tag): array {
    $production = User::query()->where('email', 'production@ogami.test')->firstOrFail();
    $product = Product::factory()->create([
        'part_number' => 'MOWN-P-'.$tag,
        'name' => 'Maintenance ownership product '.$tag,
    ]);
    $machine = Machine::factory()->create([
        'machine_code' => 'MOWN-M-'.$tag,
        'name' => 'Maintenance ownership machine '.$tag,
        'status' => 'running',
        'current_work_order_id' => null,
    ]);
    $maintenanceMachine = Machine::factory()->create([
        'machine_code' => 'MOWN-I-'.$tag,
        'name' => 'Idle maintenance machine '.$tag,
        'status' => 'idle',
        'current_work_order_id' => null,
    ]);
    $mold = Mold::create([
        'mold_code' => 'MOWN-D-'.$tag,
        'name' => 'Maintenance ownership mold '.$tag,
        'product_id' => $product->id,
        'cavity_count' => 1,
        'cycle_time_seconds' => 30,
        'output_rate_per_hour' => 120,
        'setup_time_minutes' => 5,
        'current_shot_count' => 5000,
        'max_shots_before_maintenance' => 100000,
        'lifetime_max_shots' => 1000000,
        'status' => 'in_use',
        'current_work_order_id' => null,
    ]);
    $mold->compatibleMachines()->attach($machine->id);

    $productionWorkOrder = WorkOrder::factory()->create([
        'wo_number' => 'WO-MOWN-'.$tag,
        'product_id' => $product->id,
        'machine_id' => $machine->id,
        'mold_id' => $mold->id,
        'status' => 'in_progress',
        'quantity_target' => 100,
        'quantity_produced' => 20,
        'quantity_good' => 20,
        'quantity_rejected' => 0,
        'planned_start' => now()->subHour(),
        'planned_end' => now()->addHours(2),
        'actual_start' => now()->subMinutes(30),
        'created_by' => $production->id,
    ]);
    $machine->forceFill(['current_work_order_id' => $productionWorkOrder->id])->save();

    return [
        'tag' => $tag,
        'machine' => $machine->hash_id,
        'machine_code' => $machine->machine_code,
        'mold' => $mold->hash_id,
        'mold_code' => $mold->mold_code,
        'mold_name' => $mold->name,
        'production_work_order' => $productionWorkOrder->hash_id,
        'production_wo_number' => $productionWorkOrder->wo_number,
        'maintenance_machine' => $maintenanceMachine->hash_id,
        'maintenance_machine_code' => $maintenanceMachine->machine_code,
        'maintenance_machine_name' => $maintenanceMachine->name,
    ];
});

$json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents(storage_path('app/maintenance-mold-ownership-e2e-'.$tag.'.json'), $json);
echo json_encode($fixture, JSON_THROW_ON_ERROR), PHP_EOL;
