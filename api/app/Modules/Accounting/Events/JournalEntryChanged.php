<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free ledger change notice; every viewer refetches through their API access. */
final class JournalEntryChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $journalEntryHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounting.journal-entry.{$this->journalEntryHashId}"),
            new PrivateChannel('entity.journal_entry.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'accounting.journal-entry.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'journal_entry',
            'hash_id' => $this->journalEntryHashId,
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
            'timestamp' => $this->timestamp,
        ];
    }
}
