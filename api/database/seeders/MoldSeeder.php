<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Enums\MoldEventType;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MoldHistory;
use Illuminate\Database\Seeder;

/**
 * Sprint 6 — Task 50.
 * 15 demo molds: most products have one primary mold, the high-runners get
 * a redundant secondary mold. Cycle times derived from cavity_count so the
 * MRP II scheduler (Task 53) returns plausible durations.
 */
class MoldSeeder extends Seeder
{
    private const MOLDS = [
        // partNumber, mold_code, name, cavities, cycle_seconds, max_shots, lifetime_max
        ['WB-001', 'M-WB-001', 'WB-001 4-cav steel mold A', 4,  20, 100_000, 500_000],
        ['WB-001', 'M-WB-002', 'WB-001 4-cav steel mold B', 4,  20, 100_000, 500_000],
        ['WB-002', 'M-WB-003', 'WB-002 4-cav heavy duty',   4,  24, 80_000,  400_000],
        ['PC-001', 'M-PC-001', 'PC-001 8-cav', 8,  30, 60_000, 300_000],
        ['PC-001', 'M-PC-002', 'PC-001 8-cav backup', 8,  30, 60_000, 300_000],
        ['PC-002', 'M-PC-003', 'PC-002 8-cav', 8,  30, 60_000, 300_000],
        ['RC-001', 'M-RC-001', 'RC-001 2-cav', 2,  35, 50_000, 250_000],
        ['RC-001', 'M-RC-002', 'RC-001 2-cav backup', 2, 35, 50_000, 250_000],
        ['RC-002', 'M-RC-003', 'RC-002 2-cav large', 2, 45, 40_000, 200_000],
        ['BB-001', 'M-BB-001', 'BB-001 4-cav bobbin', 4, 18, 80_000, 400_000],
        ['BB-001', 'M-BB-002', 'BB-001 4-cav bobbin backup', 4, 18, 80_000, 400_000],
        ['BU-001', 'M-BU-001', 'BU-001 6-cav', 6, 22, 70_000, 350_000],
        ['BU-001', 'M-BU-002', 'BU-001 6-cav backup', 6, 22, 70_000, 350_000],
        ['BU-001', 'M-BU-003', 'BU-001 6-cav backup 2', 6, 22, 70_000, 350_000],
        ['WB-002', 'M-WB-004', 'WB-002 4-cav backup', 4, 24, 80_000, 400_000],
    ];

    public function run(): void
    {
        $created = 0;
        foreach (self::MOLDS as [$partNumber, $code, $name, $cavities, $cycleSeconds, $maxShots, $lifetimeMax]) {
            $product = Product::where('part_number', $partNumber)->first();
            if (! $product) {
                $this->command?->warn("Product '{$partNumber}' not found — run ProductSeeder first.");
                continue;
            }

            $output = (int) floor(3600 / $cycleSeconds * $cavities);

            $mold = Mold::firstOrCreate(
                ['mold_code' => $code],
                [
                    'name'                         => $name,
                    'product_id'                   => $product->id,
                    'cavity_count'                 => $cavities,
                    'cycle_time_seconds'           => $cycleSeconds,
                    'output_rate_per_hour'         => $output,
                    'setup_time_minutes'           => 90,
                    'current_shot_count'           => 0,
                    'max_shots_before_maintenance' => $maxShots,
                    'lifetime_total_shots'         => 0,
                    'lifetime_max_shots'           => $lifetimeMax,
                    'status'                       => 'available',
                    'location'                     => 'Tooling Crib · Bay A',
                ]
            );

            // Audit row only on initial create.
            if ($mold->wasRecentlyCreated) {
                MoldHistory::create([
                    'mold_id'             => $mold->id,
                    'event_type'          => MoldEventType::Created->value,
                    'description'         => 'Initial seed.',
                    'event_date'          => now()->toDateString(),
                    'shot_count_at_event' => 0,
                ]);
                $created++;
            }
        }
        $this->command?->info("Seeded {$created} molds.");
    }
}
