<?php

declare(strict_types=1);

namespace App\Modules\Quality\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;

/** The only legal inspection lifecycle transitions. */
final class InspectionStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'draft' => ['in_progress', 'cancelled'],
        'in_progress' => ['passed', 'failed', 'cancelled'],
        'passed' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    /**
     * Validate a requested transition without mutating the aggregate.
     * Persistence remains the responsibility of InspectionService's transaction.
     */
    public function assertAllowed(Inspection $inspection, InspectionStatus $target): void
    {
        $current = $inspection->status instanceof InspectionStatus
            ? $inspection->status
            : InspectionStatus::tryFrom((string) $inspection->getRawOriginal('status'));

        if ($current === null) {
            throw new BusinessRuleException('Inspection status is invalid; the lifecycle cannot continue.');
        }

        if ($current === $target) {
            return;
        }

        if (! in_array($target->value, self::TRANSITIONS[$current->value] ?? [], true)) {
            throw new BusinessRuleException(sprintf(
                'Inspection cannot transition from %s to %s.',
                $current->label(),
                $target->label(),
            ));
        }
    }
}
