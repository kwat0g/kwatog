<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Models\StockLevelSnapshot;
use App\Modules\DSS\Services\StockSnapshotService;
use App\Modules\Inventory\Models\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_captures_every_position(): void
    {
        $levels = StockLevel::factory()->count(3)->create([
            'quantity' => 10, 'reserved_quantity' => 2, 'weighted_avg_cost' => 5.5,
        ]);

        $stats = app(StockSnapshotService::class)->takeSnapshot('2026-10-01');

        $this->assertSame('2026-10-01', $stats['date']);
        $this->assertSame(3, $stats['positions']);
        $this->assertSame(3, $stats['inserted']);
        $this->assertSame(3, StockLevelSnapshot::query()->where('snapshot_date', '2026-10-01')->count());

        $first = StockLevelSnapshot::query()
            ->where('item_id', $levels[0]->item_id)
            ->where('location_id', $levels[0]->location_id)
            ->firstOrFail();
        $this->assertSame('10.000', $first->quantity);
        $this->assertSame('2.000', $first->reserved_quantity);
        $this->assertSame('5.5000', $first->weighted_avg_cost);
    }

    public function test_rerun_is_idempotent(): void
    {
        StockLevel::factory()->count(2)->create();

        $service = app(StockSnapshotService::class);
        $first = $service->takeSnapshot('2026-10-02');
        $second = $service->takeSnapshot('2026-10-02');

        $this->assertSame(2, $first['inserted']);
        $this->assertSame(2, $second['positions']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(2, StockLevelSnapshot::query()->where('snapshot_date', '2026-10-02')->count());
    }

    public function test_snapshot_command_reports_counts(): void
    {
        StockLevel::factory()->create();

        $this->artisan('dss:snapshot-stock', ['--date' => '2026-10-03'])
            ->assertSuccessful();
        $this->assertSame(1, StockLevelSnapshot::query()->where('snapshot_date', '2026-10-03')->count());
    }
}
