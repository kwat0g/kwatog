<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * Sprint 7 — Task 66. Seed the 3 reference vehicles per spec.
 */
class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['plate_number' => 'TRK-001', 'name' => 'Truck 1',  'vehicle_type' => 'truck', 'capacity_kg' => 5000.00],
            ['plate_number' => 'TRK-002', 'name' => 'Truck 2',  'vehicle_type' => 'truck', 'capacity_kg' => 5000.00],
            ['plate_number' => 'VAN-001', 'name' => 'L300 Van', 'vehicle_type' => 'van',   'capacity_kg' => 1500.00],
        ];
        foreach ($rows as $row) {
            Vehicle::query()->updateOrCreate(
                ['plate_number' => $row['plate_number']],
                array_merge($row, ['status' => 'available']),
            );
        }
    }
}
