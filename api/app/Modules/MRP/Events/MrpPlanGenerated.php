<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

use App\Modules\MRP\Models\MrpPlan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 audit §1.7 — Fired by MrpEngineService::runForSalesOrder() once
 * the plan is committed. Drives the dashboard "MRP plan generated" pulse
 * and the SO detail page's chain header refresh.
 */
class MrpPlanGenerated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public MrpPlan $plan) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('production.dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'mrp.plan_generated';
    }

    public function broadcastWith(): array
    {
        $this->plan->loadMissing('salesOrder:id,so_number');
        return [
            'plan_id'         => $this->plan->hash_id,
            'mrp_plan_no'     => $this->plan->mrp_plan_no,
            'sales_order'     => $this->plan->salesOrder ? [
                'id'        => $this->plan->salesOrder->hash_id,
                'so_number' => $this->plan->salesOrder->so_number,
            ] : null,
            'shortages_found' => (int) $this->plan->shortages_found,
            'auto_pr_count'   => (int) $this->plan->auto_pr_count,
            'draft_wo_count'  => (int) $this->plan->draft_wo_count,
            'version'         => (int) $this->plan->version,
        ];
    }
}
