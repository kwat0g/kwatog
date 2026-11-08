<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\HR\Events\DepartmentChanged;
use App\Modules\HR\Models\Department;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DepartmentRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_update_archive_and_restore_emit_content_free_refreshes(): void
    {
        $department = Department::factory()->create();
        $this->assertSignal('created', $department);

        DB::table('event_outbox')->delete();
        $department->update(['name' => 'Updated Department']);
        $this->assertSignal('updated', $department);

        DB::table('event_outbox')->delete();
        $department->delete();
        $this->assertSignal('deleted', $department);

        DB::table('event_outbox')->delete();
        Department::withTrashed()->findOrFail($department->id)->restore();
        $this->assertSignal('restored', $department);
    }

    private function assertSignal(string $action, Department $department): void
    {
        $message = OutboxMessage::query()
            ->where('event_type', DepartmentChanged::class)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);
        $payload = $event->broadcastWith();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-entity.department.visible', $event->broadcastOn()[0]->name);
        $this->assertSame('department', $payload['entity_type']);
        $this->assertSame($department->hash_id, $payload['hash_id']);
        $this->assertSame($action, $payload['action']);
        $this->assertSame([
            'refresh', 'event_id', 'entity_type', 'hash_id', 'action', 'version', 'timestamp',
        ], array_keys($payload));
        $this->assertArrayNotHasKey('name', $payload);
        $this->assertArrayNotHasKey('code', $payload);
    }
}
