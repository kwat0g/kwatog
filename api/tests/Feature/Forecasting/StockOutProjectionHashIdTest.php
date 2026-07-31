<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Forecasting\Services\StockOutProjectionService;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockOutProjectionHashIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_exposes_a_linkable_item_hash_id(): void
    {
        $item = Item::factory()->create();

        $rows = app(StockOutProjectionService::class)->projectAll();

        $row = collect($rows)->firstWhere('code', $item->code);
        $this->assertNotNull($row);
        $this->assertSame($item->hash_id, $row['item_id']);
        $this->assertNotSame((string) $item->id, $row['item_id']);
    }
}
