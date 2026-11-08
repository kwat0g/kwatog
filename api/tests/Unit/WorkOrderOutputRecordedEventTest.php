<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use Tests\TestCase;

final class WorkOrderOutputRecordedEventTest extends TestCase
{
    public function test_output_refresh_also_targets_authorized_work_order_list_viewers(): void
    {
        $workOrder = new WorkOrder;
        $workOrder->id = 42;
        $output = new WorkOrderOutput;

        $event = new WorkOrderOutputRecorded($workOrder, $output);
        $channels = array_map(static fn ($channel): string => $channel->name, $event->broadcastOn());
        $payload = $event->broadcastWith();

        $this->assertContains('private-entity.work_order.visible', $channels);
        $this->assertContains('private-quality.dashboard', $channels);
        $this->assertSame('work_order', $payload['entity_type']);
        $this->assertSame($workOrder->hash_id, $payload['entity_id']);
        $this->assertSame('updated', $payload['action']);
        $this->assertSame($payload['timestamp'], $payload['version']);
        $this->assertNull($payload['actor_id']);
        $this->assertSame([], $payload['related']);
        $this->assertSame($event->eventId, $payload['event_id']);
    }
}
