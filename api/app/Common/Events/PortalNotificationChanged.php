<?php

declare(strict_types=1);

namespace App\Common\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/** Content-free inbox refresh for one authenticated B2B portal principal. */
final class PortalNotificationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    /** @param 'customer'|'supplier' $realm
     * @param  'created'|'read'|'deleted'  $action
     */
    public function __construct(
        public readonly string $realm,
        public readonly string $userHashId,
        public readonly string $action,
        public readonly ?string $notificationId = null,
        public readonly ?string $type = null,
        ?string $eventId = null,
    ) {
        $this->initializeBroadcastEventId($eventId);
        if (! in_array($realm, ['customer', 'supplier'], true)) {
            throw new InvalidArgumentException('Unsupported portal notification realm.');
        }
        if (! in_array($action, ['created', 'read', 'deleted'], true)) {
            throw new InvalidArgumentException('Unsupported portal notification change.');
        }
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("portal.{$this->realm}.user.{$this->userHashId}")];
    }

    public function broadcastAs(): string
    {
        return 'portal.notification.changed';
    }

    /** @return array{action:string, event_id:string, id?:string, type?:string} */
    public function broadcastWith(): array
    {
        $payload = ['action' => $this->action, 'event_id' => $this->eventId];
        if ($this->notificationId !== null) {
            $payload['id'] = $this->notificationId;
        }
        if ($this->type !== null) {
            $payload['type'] = $this->type;
        }

        return $payload;
    }
}
