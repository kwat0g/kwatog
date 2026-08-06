<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on private channel "payroll.period.{hash_id}" so the SPA period
 * detail page can show live progress while ProcessPayrollJob iterates
 * employees.
 *
 * Private, not public: a payroll run's headcount is company-wide compensation
 * metadata. routes/channels.php already gates this channel on
 * payroll.periods.view, and the SPA's useEcho() subscribes via echo.private() —
 * a public Channel here meant the authorisation was never consulted AND the
 * SPA could not receive the events at all.
 *
 * The SPA falls back to TanStack Query polling at 3s, and
 * PayrollProgressTracker caches each snapshot so a mid-run page load renders a
 * real bar immediately instead of waiting for the next broadcast.
 */
class PayrollProgressEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PayrollPeriod $period,
        public int $processed,
        public int $total,
        public int $failures,
    ) {}

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
            'period_id' => $this->period->hash_id,
            'processed' => $this->processed,
            'total'     => $this->total,
            'failures'  => $this->failures,
            'percent'   => $this->total > 0 ? (int) round(($this->processed / $this->total) * 100) : 0,
            // The final emit carries the terminal status, so a subscribed page
            // leaves the processing state immediately rather than on next poll.
            'status'    => $this->period->status?->value,
        ];
    }
}
