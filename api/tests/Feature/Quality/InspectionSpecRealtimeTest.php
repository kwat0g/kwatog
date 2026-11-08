<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\CRM\Models\Product;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectionSpecRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_spec_revision_and_child_item_changes_stage_one_parent_refresh(): void
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'status' => 'confirmed',
        ]);
        $specHash = null;
        DB::table('event_outbox')->delete();

        DB::transaction(function () use ($product, $user, &$specHash): void {
            $spec = InspectionSpec::create([
                'product_id' => $product->id,
                'version' => 1,
                'is_active' => true,
                'created_by' => $user->id,
            ]);
            $specHash = $spec->hash_id;
            InspectionSpecItem::create([
                'inspection_spec_id' => $spec->id,
                'parameter_name' => 'Visual surface',
                'acceptance_criterion' => 'No flash or sink marks.',
                'sort_order' => 0,
            ]);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', \App\Modules\Quality\Events\QualityRecordChanged::class)
            ->get();

        $this->assertCount(1, $messages, 'A spec revision and its child item writes must share one refresh.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('inspection_spec', $payload['entityType']);
        $this->assertSame($specHash, $payload['hashId']);

        $workOrderMessages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\AssignedWorkOrderChanged')
            ->get();

        $this->assertCount(1, $workOrderMessages, 'A spec change must refresh active work-order readiness for its product.');
        $workOrderPayload = json_decode((string) $workOrderMessages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $workOrderPayload['workOrderHashId']);
    }
}
