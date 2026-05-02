<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/**
 * Sprint 7 — Task 60. Inspection lifecycle.
 *
 *   draft       — created with measurement scaffold, no readings yet
 *   in_progress — at least one measurement recorded, not yet completed
 *   passed      — all measurements pass and defect_count <= accept_count
 *   failed      — at least one critical fail OR defect_count > accept_count
 *   cancelled   — voided before completion (reason captured in notes)
 */
enum InspectionStatus: string
{
    case Draft      = 'draft';
    case InProgress = 'in_progress';
    case Passed     = 'passed';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Draft',
            self::InProgress => 'In progress',
            self::Passed     => 'Passed',
            self::Failed     => 'Failed',
            self::Cancelled  => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Passed, self::Failed, self::Cancelled], true);
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
