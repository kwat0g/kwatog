<?php

declare(strict_types=1);

namespace App\Modules\HR\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Refresh-only signal for scoped HR, Finance, and request-owner views. */
final class ProfileUpdateRequestChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $requestHashId,
        public ?string $employeeHashId,
        public ?string $departmentHashId,
        public ?string $requesterHashId,
        public bool $requiresFinance,
        public string $status,
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
        $channels = [
            new PrivateChannel("profile-update-request.{$this->requestHashId}"),
            new PrivateChannel('entity.profile_update_request.hr.all'),
        ];

        if ($this->departmentHashId !== null) {
            $channels[] = new PrivateChannel("entity.profile_update_request.hr.department.{$this->departmentHashId}");
        }
        if ($this->financeLegVisible()) {
            $channels[] = new PrivateChannel('entity.profile_update_request.finance');
        }
        if ($this->requesterHashId !== null) {
            $channels[] = new PrivateChannel("profile-update-request.user.{$this->requesterHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'hr.profile-update-request.changed';
    }

    public function financeLegVisible(): bool
    {
        return $this->requiresFinance
            && in_array($this->status, ['pending_finance', 'approved', 'rejected'], true);
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // Actor identity is retained in the durable outbox but withheld from
        // the requester channel because its self-service API omits reviewers.
        $related = $this->employeeHashId === null
            ? []
            : [['entity_type' => 'employee', 'hash_id' => $this->employeeHashId]];

        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'profile_update_request',
            'entity_id' => $this->requestHashId,
            'related' => $related,
            'action' => $this->action,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
