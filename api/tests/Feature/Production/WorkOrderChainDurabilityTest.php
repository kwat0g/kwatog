<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Events\ChainStepAdvanced;
use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class WorkOrderChainDurabilityTest extends TestCase
{
    use HasPassedInProcessQc;
    use RefreshDatabase;

    private WorkOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Queue::fake();
        $this->service = app(WorkOrderService::class);
    }

    public function test_status_and_chain_events_exist_before_outer_commit_and_roll_back_together(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::Completed->value,
        ]);
        $this->qualityEvidence($workOrder);

        DB::beginTransaction();
        $this->service->close($workOrder);

        $statusOutbox = DB::table('event_outbox')
            ->where('event_type', WorkOrderStatusChanged::class)
            ->first();
        $chainOutbox = DB::table('event_outbox')
            ->where('event_type', ChainStepAdvanced::class)
            ->first();

        $this->assertNotNull($statusOutbox);
        $this->assertNotNull($chainOutbox);
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $chainOutbox->id,
            'entity_id' => $workOrder->id,
            'step' => WorkOrderStatus::Closed->value,
            'status' => 'pending',
        ]);

        DB::rollBack();

        $this->assertDatabaseMissing('event_outbox', [
            'event_type' => WorkOrderStatusChanged::class,
        ]);
        $this->assertDatabaseMissing('event_outbox', [
            'event_type' => ChainStepAdvanced::class,
        ]);
        $this->assertDatabaseMissing('chain_step_runs', [
            'entity_id' => $workOrder->id,
            'step' => WorkOrderStatus::Closed->value,
        ]);
    }

    public function test_committed_transition_keeps_both_outbox_records_and_schedules_dispatch(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $parentWorkOrder = WorkOrder::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::Completed->value,
            'parent_wo_id' => $parentWorkOrder->id,
        ]);
        $this->qualityEvidence($workOrder);

        $this->service->close($workOrder);

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => WorkOrderStatusChanged::class,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => ChainStepAdvanced::class,
            'status' => 'pending',
        ]);

        $message = DB::table('event_outbox')
            ->where('event_type', WorkOrderStatusChanged::class)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode(
            $message->event_type,
            json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        $payload = $event->broadcastWith();
        $freshWorkOrder = $workOrder->fresh();
        $this->assertSame('work_order', $payload['entity_type']);
        $this->assertSame($freshWorkOrder->hash_id, $payload['entity_id']);
        $this->assertSame('status_changed', $payload['action']);
        $this->assertSame($freshWorkOrder->updated_at->utc()->toIso8601String(), $payload['version']);
        $this->assertSame(app('hashids')->encode($actor->id), $payload['actor_id']);
        $this->assertSame($event->eventId, $payload['event_id']);
        $this->assertSame([[
            'entity_type' => 'work_order',
            'hash_id' => $parentWorkOrder->hash_id,
        ]], $payload['related']);
        Queue::assertPushed(DispatchOutboxMessage::class, 2);
    }

    public function test_soft_delete_and_restore_refresh_lists_without_fabricating_a_chain_step(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Planned->value]);
        $this->service->delete($workOrder);

        $this->assertSame(1, DB::table('event_outbox')->where('event_type', ChainStepAdvanced::class)->count());
        $this->assertDatabaseMissing('chain_step_runs', ['entity_id' => $workOrder->id]);

        $this->service->restore($workOrder, User::factory()->create()->id);

        $this->assertSame(2, DB::table('event_outbox')->where('event_type', ChainStepAdvanced::class)->count());
        $this->assertDatabaseMissing('chain_step_runs', ['entity_id' => $workOrder->id]);
    }

    public function test_draft_without_routing_refreshes_work_order_lists(): void
    {
        $creator = User::factory()->create();
        $product = Product::factory()->create();
        $workOrder = $this->service->createDraft([
            'product_id' => $product->id,
            'quantity_target' => 25,
            'planned_start' => now()->addHour()->toDateTimeString(),
            'planned_end' => now()->addDays(2)->toDateTimeString(),
            'priority' => 50,
            'created_by' => $creator->id,
        ]);

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => ChainStepAdvanced::class,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('chain_step_runs', [
            'entity_id' => $workOrder->id,
        ]);
    }

    public function test_chain_staging_failure_rolls_back_the_status_transition(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::Completed->value,
        ]);
        $this->qualityEvidence($workOrder);

        $this->mock(ChainBroadcaster::class)
            ->shouldReceive('broadcastFor')
            ->once()
            ->andThrow(new \RuntimeException('chain ledger unavailable'));

        try {
            $this->service->close($workOrder);
            $this->fail('A canonical chain staging failure must abort the lifecycle transaction.');
        } catch (\RuntimeException $e) {
            $this->assertSame('chain ledger unavailable', $e->getMessage());
        }

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'status' => WorkOrderStatus::Completed->value,
        ]);
        $this->assertDatabaseMissing('event_outbox', [
            'event_type' => WorkOrderStatusChanged::class,
        ]);
        $this->assertDatabaseMissing('event_outbox', [
            'event_type' => ChainStepAdvanced::class,
        ]);
        $this->assertDatabaseMissing('chain_step_runs', [
            'entity_id' => $workOrder->id,
            'step' => WorkOrderStatus::Closed->value,
        ]);
    }

    private function qualityEvidence(WorkOrder $wo): void
    {
        $this->passInProcessQc($wo);
        Inspection::create([
            'inspection_number' => 'CHAIN-QC-'.$wo->id, 'product_id' => $wo->product_id, 'stage' => 'outgoing', 'status' => 'passed',
            'entity_type' => 'work_order', 'entity_id' => $wo->id, 'batch_quantity' => 1, 'sample_size' => 1,
        ]);
    }
}
