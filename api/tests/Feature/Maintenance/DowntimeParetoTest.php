<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Maintenance\Services\DowntimeAnalyticsService;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
use Database\Seeders\MachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DowntimeParetoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pareto_sorts_categories_desc_and_emits_cumulative_percent(): void
    {
        $this->seed(MachineSeeder::class);
        $machineId = (int) Machine::query()->value('id');

        $this->seedDowntime($machineId, MachineDowntimeCategory::Breakdown, 600); // 60 %
        $this->seedDowntime($machineId, MachineDowntimeCategory::Changeover, 300); // 30 %
        $this->seedDowntime($machineId, MachineDowntimeCategory::MaterialShortage, 100); // 10 %

        $svc = app(DowntimeAnalyticsService::class);
        $pareto = $svc->categoryPareto(null, 30);

        $this->assertCount(3, $pareto);
        $this->assertSame('breakdown', $pareto[0]['category']);
        $this->assertSame(60.0, $pareto[0]['percent']);
        $this->assertSame(60.0, $pareto[0]['cumulative_percent']);

        $this->assertSame('changeover', $pareto[1]['category']);
        $this->assertSame(90.0, $pareto[1]['cumulative_percent']);

        $this->assertSame('material_shortage', $pareto[2]['category']);
        $this->assertSame(100.0, $pareto[2]['cumulative_percent']);
    }

    public function test_pareto_empty_window_returns_empty_array(): void
    {
        $svc = app(DowntimeAnalyticsService::class);
        $this->assertSame([], $svc->categoryPareto(null, 30));
    }

    private function seedDowntime(int $machineId, MachineDowntimeCategory $cat, int $minutes): void
    {
        MachineDowntime::create([
            'machine_id'        => $machineId,
            'work_order_id'     => null,
            'start_time'        => now()->subDays(1),
            'end_time'          => now()->subDays(1)->addMinutes($minutes),
            'duration_minutes'  => $minutes,
            'category'          => $cat->value,
            'description'       => 'Test',
        ]);
    }
}
