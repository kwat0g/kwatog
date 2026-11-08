<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Broadcast on private channel "payroll.period.{hash_id}" so the SPA period
 * detail page can show live progress while ProcessPayrollJob iterates
 * employees.
 *
 * Private, not public: a payroll run's headcount is company-wide compensation
 * metadata. routes/channels.php mirrors the period detail route's enabled
 * module, read permission, and record-existence checks; the SPA subscribes via
 * echo.private(). A public Channel would skip authorization entirely.
 *
 * The SPA falls back to TanStack Query polling at 3s, and
 * PayrollProgressTracker caches each snapshot so a mid-run page load renders a
 * real bar immediately instead of waiting for the next broadcast.
 */
class PayrollProgressEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public PayrollPeriod $period,
        public int $processed,
        public int $total,
        public int $failures,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("payroll.period.{$this->period->hash_id}");
    }

    /**
     * Stable wire name. Without this the event name is the FQCN, so any
     * namespace move would silently break every subscribed client.
     */
    public function broadcastAs(): string
    {
        return 'payroll.progress';
    }

    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'action' => $this->period->status === PayrollPeriodStatus::Processing ? 'progress' : 'completed',
            'event_id' => $this->eventId,
        ];
    }
}
