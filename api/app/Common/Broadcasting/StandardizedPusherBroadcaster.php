<?php

declare(strict_types=1);

namespace App\Common\Broadcasting;

use App\Common\Events\BroadcastEventEnvelope;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Pusher\Pusher;

/** Applies the shared refresh envelope to every application Pusher/Reverb broadcast. */
final class StandardizedPusherBroadcaster extends PusherBroadcaster
{
    /** Reserve the Reverb HTTP request line and headers beyond the JSON body. */
    private const HTTP_REQUEST_HEADROOM_BYTES = 1024;

    /**
     * Only refresh metadata may cross the transport. Keep legacy opaque-ID
     * aliases and notification identifiers needed by existing consumers;
     * record fields must be fetched from the viewer-authorized API instead.
     * The socket value is transport metadata consumed by PusherBroadcaster.
     *
     * @var list<string>
     */
    private const REFRESH_PAYLOAD_KEYS = [
        'refresh', 'refresh_required', 'event_id', 'entity_type', 'entity_id', 'entity_ids', 'hash_id',
        'action', 'version', 'actor_id', 'actor_hash_id', 'related', 'related_entities', 'timestamp',
        'occurred_at', 'group', 'id', 'type', 'socket',
    ];

    public function __construct(Pusher $pusher, bool $allowJsonp = false, private readonly ?int $maxRequestSize = null)
    {
        parent::__construct($pusher, $allowJsonp);
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $payload = BroadcastEventEnvelope::normalizeBroadcastPayload((string) $event, $payload);
        $payload = array_intersect_key($payload, array_flip(self::REFRESH_PAYLOAD_KEYS));
        if ($this->maxRequestSize !== null) {
            $maxRequestSize = max(1, $this->maxRequestSize);
            $requestSize = $this->estimatedRequestSize($channels, (string) $event, $payload);

            if ($requestSize > $maxRequestSize) {
                throw new BroadcastException(sprintf(
                    'Realtime event %s request is estimated at %d bytes, exceeding the configured Reverb max_request_size of %d bytes.',
                    (string) $event,
                    $requestSize,
                    $maxRequestSize,
                ));
            }
        }

        parent::broadcast(
            $channels,
            $event,
            $payload,
        );
    }

    /**
     * Estimate each Pusher REST body using its wire shape and reserve space
     * for HTTP headers/request target. Reverb enforces max_request_size on the
     * complete buffered HTTP request, not on the WebSocket app message limit.
     *
     * @param  array<int, mixed>  $channels
     * @param  array<string, mixed>  $payload
     */
    private function estimatedRequestSize(array $channels, string $event, array $payload): int
    {
        $socket = $payload['socket'] ?? null;
        unset($payload['socket']);

        $largestRequest = 0;
        $channelBatches = array_chunk($this->formatChannels($channels), 100);

        foreach ($channelBatches as $channelBatch) {
            $request = [
                'name' => $event,
                // The Pusher REST API encodes event data as a JSON string in
                // the outer request body.
                'data' => json_encode($payload, JSON_THROW_ON_ERROR),
            ];

            if (count($channelBatch) === 1) {
                $request['channel'] = $channelBatch[0];
            } else {
                $request['channels'] = $channelBatch;
            }

            if ($socket !== null) {
                $request['socket_id'] = $socket;
            }

            $bodySize = strlen(json_encode($request, JSON_THROW_ON_ERROR));
            $largestRequest = max($largestRequest, $bodySize + self::HTTP_REQUEST_HEADROOM_BYTES);
        }

        return $largestRequest;
    }
}
