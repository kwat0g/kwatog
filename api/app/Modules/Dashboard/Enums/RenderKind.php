<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Enums;

/**
 * How a dashboard widget draws itself. Presentation only — visibility is
 * still decided solely by `dashboard_widgets.permission`.
 */
enum RenderKind: string
{
    case Scalar = 'scalar';
    case Breakdown = 'breakdown';
    case Trend = 'trend';
    case Table = 'table';
    case Gauge = 'gauge';

    /**
     * Unknown or missing kinds degrade to a scalar tile rather than throwing:
     * a stale row left by a rolled-back deploy must not break every dashboard
     * that happens to include it.
     */
    public static function fromNullable(?string $value): self
    {
        return $value === null ? self::Scalar : (self::tryFrom($value) ?? self::Scalar);
    }

    /** The payload key this kind carries in the rich layout response. */
    public function payloadKey(): string
    {
        return match ($this) {
            self::Scalar => 'value',
            self::Breakdown => 'segments',
            self::Trend => 'points',
            self::Table => 'rows',
            self::Gauge => 'value',
        };
    }
}
