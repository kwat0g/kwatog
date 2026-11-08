<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Content-free access and session refresh for exact affected internal users. */
final class UserAccessChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    private const MAX_RECIPIENT_CHANNELS = 50;

    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    /** @param list<string> $userHashIds */
    public function __construct(public array $userHashIds, ?string $eventId = null)
    {
        $this->userHashIds = array_values(array_unique($userHashIds));
        if (count($this->userHashIds) > self::MAX_RECIPIENT_CHANNELS) {
            throw new InvalidArgumentException('User access refresh batches may target at most 50 channels.');
        }
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $userHashId): PrivateChannel => new PrivateChannel("user.access.{$userHashId}"),
            $this->userHashIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'user.access.changed';
    }

    /** @return array{refresh: true, event_id: string} */
    public function broadcastWith(): array
    {
        return ['refresh' => true, 'event_id' => $this->eventId];
    }
}
