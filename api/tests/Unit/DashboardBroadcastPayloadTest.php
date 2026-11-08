<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\AdminBackupsChanged;
use App\Common\Events\CacheGroupInvalidated;
use App\Common\Events\ChainListenerRunsChanged;
use App\Common\Events\ToleratesNewerModelState;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Admin\Events\AdminSessionsChanged;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Events\MoldShotLimitNearing;
use App\Modules\MRP\Events\MoldShotLimitReached;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Tests\TestCase;

class DashboardBroadcastPayloadTest extends TestCase
{
    public function test_shared_dashboard_events_only_send_a_refresh_ping(): void
    {
        $workOrder = new WorkOrder;
        $machine = new Machine;
        $mold = new Mold;

        $events = [
            new MrpPlanGenerated(new MrpPlan),
        ];

        foreach ($events as $event) {
            $this->assertSame([
                'refresh' => true,
                'event_id' => $event->eventId,
            ], $event->broadcastWith(), $event::class);
        }
    }

    public function test_work_order_status_signal_uses_the_standard_opaque_record_envelope(): void
    {
        $workOrder = new WorkOrder;
        $workOrder->id = 42;
        $workOrder->updated_at = CarbonImmutable::parse('2026-11-03T14:00:00+00:00');
        $event = new WorkOrderStatusChanged(
            workOrder: $workOrder,
            from: 'confirmed',
            to: 'in_progress',
            eventId: 'work-order-status-event',
            actorHashId: 'actor-hash-id',
            timestamp: '2026-11-03T14:00:01+00:00',
            parentWorkOrderHashId: 'parent-work-order-hash',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'work-order-status-event',
            'entity_type' => 'work_order',
            'entity_id' => $workOrder->hash_id,
            'action' => 'status_changed',
            'version' => '2026-11-03T06:00:00+00:00',
            'actor_id' => 'actor-hash-id',
            'related' => [['entity_type' => 'work_order', 'hash_id' => 'parent-work-order-hash']],
            'timestamp' => '2026-11-03T14:00:01+00:00',
        ], $event->broadcastWith());
        $this->assertSame(
            'private-chain.work_order.parent-work-order-hash',
            $event->broadcastOn()[2]->name,
        );
    }

    public function test_work_order_output_signal_uses_the_standard_opaque_record_envelope(): void
    {
        $workOrder = new WorkOrder;
        $workOrder->id = 42;
        $workOrder->updated_at = CarbonImmutable::parse('2026-11-03T14:00:00+00:00');
        $workOrder->exists = true;
        $output = new WorkOrderOutput;
        $output->id = 7;
        $output->recorded_by = 23;
        $output->exists = true;
        $event = new WorkOrderOutputRecorded(
            workOrder: $workOrder,
            output: $output,
            eventId: 'work-order-output-event',
            timestamp: '2026-11-03T14:00:01+00:00',
            parentWorkOrderHashId: 'parent-work-order-hash',
        );
        $expected = [
            'refresh' => true,
            'event_id' => 'work-order-output-event',
            'entity_type' => 'work_order',
            'entity_id' => $workOrder->hash_id,
            'action' => 'updated',
            'version' => '2026-11-03T06:00:00+00:00',
            'actor_id' => app('hashids')->encode(23),
            'related' => [['entity_type' => 'work_order', 'hash_id' => 'parent-work-order-hash']],
            'timestamp' => '2026-11-03T14:00:01+00:00',
        ];

        $this->assertSame($expected, $event->broadcastWith());
        $this->assertSame('private-chain.work_order.parent-work-order-hash', $event->broadcastOn()[4]->name);

    }

    public function test_mold_threshold_signals_use_standard_envelopes_and_tolerate_newer_model_state(): void
    {
        $mold = new Mold;
        $mold->id = 9;
        $mold->updated_at = CarbonImmutable::parse('2026-11-03T14:00:00+00:00');
        $events = [
            [new MoldShotLimitNearing($mold, 'mold-nearing-event', '2026-11-03T14:00:01+00:00'), 'updated'],
            [new MoldShotLimitReached($mold, 'mold-reached-event', '2026-11-03T14:00:02+00:00'), 'status_changed'],
        ];

        foreach ($events as [$event, $action]) {
            $this->assertInstanceOf(ToleratesNewerModelState::class, $event);
            $this->assertSame([
                'refresh' => true,
                'event_id' => $event->eventId,
                'entity_type' => 'mold',
                'entity_id' => $mold->hash_id,
                'action' => $action,
                'version' => '2026-11-03T06:00:00+00:00',
                'actor_id' => null,
                'related' => [],
                'timestamp' => $event->timestamp,
            ], $event->broadcastWith());
            $this->assertSame('private-production.dashboard', $event->broadcastOn()[0]->name);
        }
    }

    public function test_sales_order_confirmation_uses_standard_envelope_without_email_snapshot_data(): void
    {
        $salesOrder = new SalesOrder;
        $salesOrder->id = 11;
        $salesOrder->updated_at = CarbonImmutable::parse('2026-11-03T14:00:00+00:00');
        $event = new SalesOrderConfirmed(
            salesOrder: $salesOrder,
            emailSnapshot: ['customer_email' => 'private@example.test', 'total_amount' => '123.45'],
            actorUserId: 23,
            eventId: 'sales-order-confirmed-event',
            timestamp: '2026-11-03T14:00:01+00:00',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'sales-order-confirmed-event',
            'entity_type' => 'sales_order',
            'entity_id' => $salesOrder->hash_id,
            'action' => 'status_changed',
            'version' => '2026-11-03T06:00:00+00:00',
            'actor_id' => app('hashids')->encode(23),
            'related' => [],
            'timestamp' => '2026-11-03T14:00:01+00:00',
        ], $event->broadcastWith());
        $this->assertSame('private-production.dashboard', $event->broadcastOn()[0]->name);
    }

    public function test_machine_and_breakdown_status_events_are_domain_signals(): void
    {
        $this->assertFalse(is_subclass_of(MachineStatusChanged::class, ShouldBroadcast::class));
        $this->assertFalse(is_subclass_of(MachineBreakdownDetected::class, ShouldBroadcast::class));
    }

    public function test_cache_group_invalidation_payload_contains_only_group_and_trace_metadata(): void
    {
        $event = new CacheGroupInvalidated('procurement-chain', 'event-1', '2026-10-31T00:00:00Z');

        $this->assertSame('cache.group.invalidated', $event->broadcastAs());
        $this->assertSame(['refresh' => true, 'group' => 'procurement-chain', 'event_id' => 'event-1', 'timestamp' => '2026-10-31T00:00:00Z'], $event->broadcastWith());
        $this->assertSame('private-cache-group.procurement-chain', $event->broadcastOn()[0]->name);
    }

    public function test_admin_backup_signal_only_contains_opaque_refresh_metadata(): void
    {
        $event = new AdminBackupsChanged(
            entityType: 'backup_operation',
            entityHashId: 'c5ca4d115d4b',
            action: 'status_changed',
            version: '2026-10-31T00:00:00.000000Z',
            actorHashId: 'actor-hash',
            eventId: 'event-1',
            timestamp: '2026-10-31T00:00:01Z',
        );

        $this->assertSame('admin.backups.changed', $event->broadcastAs());
        $this->assertSame('private-admin.backups', $event->broadcastOn()[0]->name);
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-1',
            'entity_type' => 'backup_operation',
            'entity_id' => 'c5ca4d115d4b',
            'action' => 'status_changed',
            'version' => '2026-10-31T00:00:00.000000Z',
            'actor_id' => 'actor-hash',
            'related_entities' => [],
            'timestamp' => '2026-10-31T00:00:01Z',
        ], $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_admin_session_signal_does_not_expose_the_session_identifier(): void
    {
        $event = new AdminSessionsChanged(
            action: 'deleted',
            version: '2026-10-31T00:00:00.000000Z',
            actorHashId: 'opaque-actor-hash',
            eventId: 'event-session-1',
            timestamp: '2026-10-31T00:00:01Z',
        );

        $this->assertSame('admin.sessions.changed', $event->broadcastAs());
        $this->assertSame('private-admin.sessions', $event->broadcastOn()[0]->name);
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-session-1',
            'entity_type' => 'admin_session',
            'action' => 'deleted',
            'version' => '2026-10-31T00:00:00.000000Z',
            'actor_id' => 'opaque-actor-hash',
            'timestamp' => '2026-10-31T00:00:01Z',
        ], $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_chain_listener_run_signal_omits_errors_and_operator_notes(): void
    {
        $event = new ChainListenerRunsChanged(
            entityHashId: 'opaque-run-hash',
            action: 'status_changed',
            version: '2026-10-31T00:00:00.000000Z',
            actorHashId: 'opaque-actor-hash',
            eventId: 'event-2',
            timestamp: '2026-10-31T00:00:01Z',
        );

        $this->assertSame('chain.listener-runs.changed', $event->broadcastAs());
        $this->assertSame('private-chain.listener-runs', $event->broadcastOn()[0]->name);
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-2',
            'entity_type' => 'chain_listener_run',
            'entity_id' => 'opaque-run-hash',
            'action' => 'status_changed',
            'version' => '2026-10-31T00:00:00.000000Z',
            'actor_id' => 'opaque-actor-hash',
            'related_entities' => [],
            'timestamp' => '2026-10-31T00:00:01Z',
        ], $event->broadcastWith());
    }
}
