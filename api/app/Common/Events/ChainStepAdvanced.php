<?php

declare(strict_types=1);

namespace App\Common\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C4. Single canonical broadcast for "this chain advanced
 * a step." Detail pages on the SPA subscribe to
 * `private-chain.{entityType}.{hashId}` and invalidate the matching
 * TanStack Query so the UI updates without a manual refresh.
 *
 * Why one event for many entity types: every chain (SO, PO, WO, Delivery,
 * GRN) has the same shape of "active step + completed prefix" and the SPA
 * already has a uniform `<ChainHeader>` component. A single event keeps
 * the SPA hook generic and avoids one bespoke channel per chain.
 *
 * **Security note:** the payload contains only document numbers, status,
 * and an optional actor display name. NO PII, NO money figures, NO line
 * items. Channel access is gated by per-entity-type permissions in
 * routes/channels.php. Do NOT extend the payload without a security review.
 */
class ChainStepAdvanced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param string             $entityType    one of ChainDefinitions::allowedTypes()
     * @param string             $entityHashId  hashed id (never raw int)
     * @param string             $docNumber     human-readable doc no (e.g. SO-202604-0003)
     * @param string             $newStatus     the resulting status string
     * @param string             $activeStep    derived from ChainDefinitions::resolve()
     * @param array<int,string>  $completedSteps prefix of chain that is already done
     * @param string|null        $actorName     who triggered the change (display name)
     */
    public function __construct(
        public string $entityType,
        public string $entityHashId,
        public string $docNumber,
        public string $newStatus,
        public string $activeStep,
        public array  $completedSteps,
        public ?string $actorName = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chain.{$this->entityType}.{$this->entityHashId}")];
    }

    public function broadcastAs(): string
    {
        return 'chain.step_advanced';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'entity_type'     => $this->entityType,
            'entity_id'       => $this->entityHashId,
            'doc_number'      => $this->docNumber,
            'new_status'      => $this->newStatus,
            'active_step'     => $this->activeStep,
            'completed_steps' => $this->completedSteps,
            'actor_name'      => $this->actorName,
        ];
    }
}
