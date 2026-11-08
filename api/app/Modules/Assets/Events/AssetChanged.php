<?php

declare(strict_types=1);

namespace App\Modules\Assets\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Private, content-free asset refresh signal. Recipients refetch through the API. */
final class AssetChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    /**
     * @param  list<string>  $assetHashIds  Opaque IDs are used for private
     *                                       channels and carried as refresh references.
     */
    public function __construct(
        public array $assetHashIds,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('entity.asset.visible')];
        foreach (array_values(array_unique($this->assetHashIds)) as $assetHashId) {
            $channels[] = new PrivateChannel("asset.{$assetHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'asset.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $payload = [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'asset',
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
        ];

        if (count($this->assetHashIds) === 1) {
            $payload['entity_id'] = $this->assetHashIds[0];
        } else {
            $payload['entity_ids'] = $this->assetHashIds;
        }

        return $payload;
    }
}
