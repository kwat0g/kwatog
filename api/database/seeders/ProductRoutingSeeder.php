<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Services\ProductionRoutingService;
use Illuminate\Database\Seeder;

class ProductRoutingSeeder extends Seeder
{
    private const ROUTINGS = [
        'WB-001' => ['M-WB-001', 'IMM-01'],
        'WB-002' => ['M-WB-003', 'IMM-01'],
        'PC-001' => ['M-PC-001', 'IMM-03'],
        'PC-002' => ['M-PC-003', 'IMM-03'],
        'RC-001' => ['M-RC-001', 'IMM-05'],
        'BB-001' => ['M-BB-001', 'IMM-01'],
        'BU-001' => ['M-BU-001', 'IMM-03'],
        'RC-002' => ['M-RC-003', 'IMM-05'],
    ];

    public function run(): void
    {
        $routingService = app(ProductionRoutingService::class);
        $demoRates = config('app.seed_demo_data')
            ? ['labor_rate_per_hour' => '120.0000', 'machine_rate_per_hour' => '250.0000', 'overhead_rate_per_hour' => '80.0000']
            : ['labor_rate_per_hour' => '0.0000', 'machine_rate_per_hour' => '0.0000', 'overhead_rate_per_hour' => '0.0000'];
        $created = 0;

        foreach (self::ROUTINGS as $partNumber => [$moldCode, $machineCode]) {
            $product = Product::query()->where('part_number', $partNumber)->first();
            $mold = Mold::query()->where('mold_code', $moldCode)->first();
            $machine = Machine::query()->where('machine_code', $machineCode)->first();

            if ($product === null || $mold === null || (int) $mold->cavity_count < 1) {
                $this->command?->warn("Routing for {$partNumber} skipped: product, mold, machine, or compatibility is missing.");

                continue;
            }

            if ($machine === null || ! in_array($machine->status, [MachineStatus::Idle, MachineStatus::Running], true)
                || ! $mold->compatibleMachines()->whereKey($machine->id)->exists()) {
                $machine = $mold->compatibleMachines()
                    ->whereIn('machines.status', [MachineStatus::Idle->value, MachineStatus::Running->value])
                    ->orderBy('machines.machine_code')
                    ->first();
            }

            if ($machine === null) {
                $this->command?->warn("Routing for {$partNumber} skipped: no compatible machine is available for scheduling.");

                continue;
            }

            $active = ProductRouting::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->withCount('operations')
                ->first();
            if ($active !== null && $active->operations_count > 0) {
                continue;
            }

            // Mold cycle is a machine shot; divide by cavities for per-piece cost and round duration up.
            $cycleTime = number_format(
                ceil($mold->cycle_time_seconds * 100 / ($mold->cavity_count * 60)) / 100,
                2,
                '.',
                '',
            );

            $routingService->create([
                'product_id' => $product->id,
                'notes' => config('app.seed_demo_data')
                    ? 'Demo costing assumptions only: labor ₱120/hour, machine ₱250/hour, overhead ₱80/hour. Replace with PPC/Finance-approved rates before production use.'
                    : 'Seeded process from mold cycle and setup data. Configure approved labor, machine, and overhead rates before using full manufacturing cost estimates.',
                'operations' => [[
                    'sequence' => 1,
                    'operation_name' => 'Injection molding',
                    'work_center' => 'Injection molding',
                    'machine_id' => $machine->id,
                    'mold_id' => $mold->id,
                    'setup_time_minutes' => (string) $mold->setup_time_minutes,
                    'cycle_time_minutes' => $cycleTime,
                    'description' => 'Per-piece cycle time derived from mold shot time and cavity count.',
                    ...$demoRates,
                ]],
            ]);
            $created++;
        }

        $this->command?->info("Seeded {$created} product routings.");
    }
}
