<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Services\OutboxEventCodec;
use App\Common\Services\OutboxService;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Events\MoldShotLimitNearing;
use App\Modules\MRP\Events\MoldShotLimitReached;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\MoldService;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MoldRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mold_change_refreshes_assigned_work_order_even_without_active_crew(): void
    {
        $product = Product::factory()->create();
        $mold = Mold::create([
            'mold_code' => 'READINESS-MOLD',
            'name' => 'Readiness mold',
            'product_id' => $product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots' => 100000,
            'status' => 'available',
        ]);
        $workOrder = WorkOrder::factory()->create(['mold_id' => $mold->id]);
        DB::table('event_outbox')->delete();

        $mold->update(['status' => 'in_use']);

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\AssignedWorkOrderChanged')
            ->get();

        $this->assertCount(1, $messages, 'An equipment change affects work-order readiness without requiring a crew assignment.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $payload['workOrderHashId']);
    }

    public function test_mold_create_and_update_coalesce_one_refresh_signal(): void
    {
        $product = Product::factory()->create();

        DB::transaction(function () use ($product): void {
            $mold = Mold::create([
                'mold_code' => 'REALTIME-MOLD',
                'name' => 'Realtime mold',
                'product_id' => $product->id,
                'cavity_count' => 1,
                'cycle_time_seconds' => 30,
                'output_rate_per_hour' => 100,
                'max_shots_before_maintenance' => 1000,
                'lifetime_max_shots' => 100000,
                'status' => 'available',
            ]);
            $mold->update(['name' => 'Updated realtime mold']);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\MoldChanged')
            ->get();

        $this->assertCount(1, $messages, 'Mold writes must stage one final refresh per record and transaction.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('updated', $payload['action']);
        $this->assertNotEmpty($payload['moldHashId']);
        $this->assertNotEmpty($payload['version']);
        $this->assertSame('pending', $messages->first()->status);
    }

    public function test_mold_threshold_outbox_signals_survive_a_newer_mold_version(): void
    {
        $product = Product::factory()->create();
        $mold = Mold::create([
            'mold_code' => 'REALTIME-THRESHOLD',
            'name' => 'Threshold replay mold',
            'product_id' => $product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots' => 100000,
            'status' => 'available',
        ]);

        foreach ([MoldShotLimitNearing::class, MoldShotLimitReached::class] as $index => $eventClass) {
            $event = new $eventClass($mold->fresh(), 'mold-threshold-event-'.$index);
            $message = app(OutboxService::class)->record($event, 'mold-threshold-replay-'.$index);
            $mold->update(['name' => 'Mold advanced '.$index]);

            $decoded = app(OutboxEventCodec::class)->decode(
                $message->event_type,
                $message->payload,
            );
            $payload = $decoded->broadcastWith();

            $this->assertSame('mold', $payload['entity_type']);
            $this->assertSame($mold->hash_id, $payload['entity_id']);
            $this->assertSame($mold->fresh()->updated_at->utc()->toIso8601String(), $payload['version']);
            $this->assertSame($event->eventId, $payload['event_id']);
        }
    }

    public function test_mold_archive_and_restore_each_stage_one_signal(): void
    {
        $product = Product::factory()->create();
        $mold = Mold::create([
            'mold_code' => 'REALTIME-RESTORE',
            'name' => 'Restore realtime mold',
            'product_id' => $product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots' => 100000,
            'status' => 'available',
        ]);
        DB::table('event_outbox')->delete();

        $service = app(MoldService::class);
        $service->delete($mold);

        $deleted = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\MoldChanged')
            ->get();
        $this->assertCount(1, $deleted);
        $this->assertSame('deleted', json_decode((string) $deleted->first()->payload, true, flags: JSON_THROW_ON_ERROR)['action']);

        DB::table('event_outbox')->delete();
        $service->restore($mold);

        $restored = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\MoldChanged')
            ->get();
        $this->assertCount(1, $restored, 'Restore fires both updated and restored model callbacks.');
        $this->assertSame('restored', json_decode((string) $restored->first()->payload, true, flags: JSON_THROW_ON_ERROR)['action']);
    }
}
