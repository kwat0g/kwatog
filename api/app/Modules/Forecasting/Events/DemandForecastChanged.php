<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free signal that Forecasting records and derived forecast views changed. */
final class DemandForecastChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ?string $forecastHashId,
        public string $action,
        public string $version,
        public string $eventId = '',
        public string $timestamp = '',
    ) {
        $this->eventId = $eventId !== '' ? $eventId : (string) Str::uuid();
        $this->timestamp = $timestamp !== '' ? $timestamp : now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('entity.demand_forecast.visible'),
            new PrivateChannel('dashboard.purchasing'),
        ];

        if ($this->forecastHashId !== null) {
            $channels[] = new PrivateChannel("forecast.demand-forecast.{$this->forecastHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'forecast.demand-forecast.changed';
    }

    /** @return array{refresh: true, event_id: string, entity_type: string, action: string, version: string, timestamp: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'demand_forecast',
            'action' => $this->action,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
