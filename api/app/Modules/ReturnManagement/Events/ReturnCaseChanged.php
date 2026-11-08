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

/** Content-free private refresh signal for return-case viewers. */
final class ReturnCaseChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    /** @param list<string> $listScopes */
    public function __construct(
        public string $hashId,
        public string $action,
        public string $version,
        public array $listScopes,
        public ?string $customerHashId = null,
        public ?string $supplierVendorHashId = null,
        public ?int $actorId = null,
        public bool $publicChange = false,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("return_case.{$this->hashId}"),
            ...array_map(
                static fn (string $scope): PrivateChannel => new PrivateChannel("entity.return_case.{$scope}"),
                $this->listScopes,
            ),
        ];

        if ($this->publicChange && $this->customerHashId !== null) {
            $channels[] = new PrivateChannel("customer.account.{$this->customerHashId}");
        }
        if ($this->publicChange && $this->supplierVendorHashId !== null) {
            $channels[] = new PrivateChannel("supplier.vendor.{$this->supplierVendorHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'return.case.refresh';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'hash_id' => $this->hashId,
            'version' => $this->version,
        ];
    }
}
