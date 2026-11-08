<?php

declare(strict_types=1);

namespace App\Modules\B2B\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Content-free signal to an exact portal principal whose access was revoked. */
final class PortalAccountAccessRevoked implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $realm,
        public string $userHashId,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        if (! in_array($realm, ['customer', 'supplier'], true)) {
            throw new InvalidArgumentException('Portal access revocation requires a customer or supplier realm.');
        }

        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("portal.{$this->realm}.user.{$this->userHashId}");
    }

    public function broadcastAs(): string
    {
        return 'b2b.portal-access.revoked';
    }

    /** @return array{refresh: true, event_id: string, entity_type: string, timestamp: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'b2b_portal_access',
            'timestamp' => $this->timestamp,
        ];
    }
}
