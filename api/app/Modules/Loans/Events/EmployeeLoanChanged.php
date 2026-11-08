<?php

declare(strict_types=1);

namespace App\Modules\Loans\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free refresh signal for authorized loan readers and the borrower. */
final class EmployeeLoanChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $loanHashId,
        public ?string $ownerUserHashId,
        public string $action,
        public string $version,
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
            new PrivateChannel("employee-loan.{$this->loanHashId}"),
            new PrivateChannel('entity.employee_loan.visible'),
        ];

        if ($this->ownerUserHashId !== null) {
            $channels[] = new PrivateChannel("loan.user.{$this->ownerUserHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'loan.changed';
    }

    /**
     * The payload contains no employee, amount, approval, payment, or actor
     * fields. Subscribers refetch through their own scoped API.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'employee_loan',
            'action' => $this->action,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
