<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Events\ChainStepAdvanced;
use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Services\ChainBroadcaster;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkOrderChainDurabilityTest extends TestCase
{
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
            'step'      => WorkOrderStatus::Closed->value,
            'status'    => 'pending',
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
            'step'      => WorkOrderStatus::Closed->value,
        ]);
    }

    public function test_committed_transition_keeps_both_outbox_records_and_schedules_dispatch(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::Completed->value,
        ]);

        $this->service->close($workOrder);

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => WorkOrderStatusChanged::class,
            'status'     => 'pending',
        ]);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => ChainStepAdvanced::class,
            'status'     => 'pending',
        ]);
        Queue::assertPushed(DispatchOutboxMessage::class, 2);
    }

    public function test_chain_staging_failure_rolls_back_the_status_transition(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::Completed->value,
        ]);

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
            'id'     => $workOrder->id,
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
            'step'      => WorkOrderStatus::Closed->value,
        ]);
    }
}
