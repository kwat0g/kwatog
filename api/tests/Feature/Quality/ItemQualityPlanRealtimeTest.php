<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Events\QualityRecordChanged;
use App\Modules\Quality\Models\ItemQualityPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItemQualityPlanRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_revision_changes_coalesce_to_a_refresh_for_the_item_plan_list(): void
    {
        $item = Item::factory()->create();
        $user = User::factory()->create();

        DB::transaction(function () use ($item, $user): void {
            $plan = ItemQualityPlan::create([
                'item_id' => $item->id,
                'version' => 1,
                'stage' => 'incoming',
                'sampling_method' => 'fixed',
                'fixed_sample_size' => 2,
                'parameters' => [['parameter_name' => 'Appearance']],
                'effective_from' => now()->toDateString(),
                'is_active' => true,
                'created_by' => $user->id,
            ]);

            $plan->update(['is_active' => false, 'effective_to' => now()->toDateString()]);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', QualityRecordChanged::class)
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('quality_plan', $payload['entityType']);
        $this->assertSame($item->hash_id, $payload['hashId']);
        $this->assertSame('updated', $payload['action']);
    }
}
