<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\HR\Events\PositionChanged;
use App\Modules\HR\Models\Position;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PositionRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_update_archive_and_restore_emit_content_free_refreshes(): void
    {
        $position = Position::factory()->create();
        $this->assertSignal('created', $position);

        DB::table('event_outbox')->delete();
        $position->update(['title' => 'Updated Position']);
        $this->assertSignal('updated', $position);

        DB::table('event_outbox')->delete();
        $position->delete();
        $this->assertSignal('deleted', $position);

        DB::table('event_outbox')->delete();
        Position::withTrashed()->findOrFail($position->id)->restore();
        $this->assertSignal('restored', $position);
    }

    private function assertSignal(string $action, Position $position): void
    {
        $message = OutboxMessage::query()
            ->where('event_type', PositionChanged::class)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);
        $payload = $event->broadcastWith();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-entity.position.visible', $event->broadcastOn()[0]->name);
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[1]);
        $this->assertSame("private-position.{$position->hash_id}", $event->broadcastOn()[1]->name);
        $this->assertSame('position', $payload['entity_type']);
        $this->assertSame($position->hash_id, $payload['hash_id']);
        $this->assertSame($action, $payload['action']);
        $this->assertSame([
            'refresh', 'event_id', 'entity_type', 'hash_id', 'action', 'version', 'timestamp',
        ], array_keys($payload));
        $this->assertArrayNotHasKey('title', $payload);
        $this->assertArrayNotHasKey('salary_grade', $payload);
    }
}
