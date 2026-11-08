<?php

declare(strict_types=1);

namespace App\Common\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Refreshes only the preference owner's open tabs; it contains no settings data. */
final class UserNotificationPreferencesChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $userHashId,
        public string $version,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.notification-preferences.{$this->userHashId}");
    }

    public function broadcastAs(): string
    {
        return 'auth.notification-preferences.changed';
    }

    /** @return array{refresh: true, event_id: string, entity_type: string, action: string, version: string, timestamp: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'notification_preference',
            'action' => 'updated',
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
