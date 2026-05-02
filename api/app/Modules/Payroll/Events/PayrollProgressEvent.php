<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on channel "payroll.period.{hash_id}" so the SPA period detail
 * page can show live progress while ProcessPayrollJob iterates employees.
 *
 * Sprint 8 / Task 78 wires Laravel Reverb. For now this just exists so
 * controllers / tests can dispatch it without errors; the SPA falls back to
 * TanStack Query polling at 3s.
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

    /**
     * @return Channel
     */
    public function broadcastOn(): Channel
    {
        return new Channel("payroll.period.{$this->period->hash_id}");
    }

    public function broadcastWith(): array
    {
        return [
            'period_id' => $this->period->hash_id,
            'processed' => $this->processed,
            'total'     => $this->total,
            'failures'  => $this->failures,
            'percent'   => $this->total > 0 ? (int) round(($this->processed / $this->total) * 100) : 0,
        ];
    }
}
