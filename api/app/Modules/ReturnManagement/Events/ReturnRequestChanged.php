<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free return refresh ping; every recipient refetches through the API. */
final class ReturnRequestChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public function __construct(
        public string $hashId,
        public string $action,
        public string $version,
        public array $listScopes,
        public ?int $actorId = null,
        ?string $eventId = null,
        public ?string $customerHashId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("return_request.{$this->hashId}"),
            ...array_map(
                static fn (string $scope): PrivateChannel => new PrivateChannel("entity.return_request.{$scope}"),
                $this->listScopes,
            ),
        ];

        if ($this->customerHashId !== null) {
            $channels[] = new PrivateChannel("customer.account.{$this->customerHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'return.request.refresh';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'version' => $this->version,
            'action' => $this->action,
        ];
    }
}
