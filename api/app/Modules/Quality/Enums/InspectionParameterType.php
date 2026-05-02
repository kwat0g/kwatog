<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/**
 * Sprint 7 — Task 59. Three flavours of inspection parameter.
 *
 *  - Dimensional: numeric + unit + tolerance window (most common)
 *  - Visual: pass/fail aesthetic check (no numeric tolerance)
 *  - Functional: numeric or pass/fail behaviour test (e.g. click force,
 *    snap-fit retention) — uses the same numeric tolerance window when
 *    the spec is measurable.
 */
enum InspectionParameterType: string
{
    case Dimensional = 'dimensional';
    case Visual      = 'visual';
    case Functional  = 'functional';

    public function label(): string
    {
        return match ($this) {
            self::Dimensional => 'Dimensional',
            self::Visual      => 'Visual',
            self::Functional  => 'Functional',
        };
    }
}
