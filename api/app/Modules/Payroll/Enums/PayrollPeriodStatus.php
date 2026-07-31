<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollPeriodStatus: string
{
    case Draft      = 'draft';
    case Processing = 'processing';
    case Computed   = 'computed';
    case Approved   = 'approved';
    case Finalized  = 'finalized';
    case Disbursed  = 'disbursed';
    case Voided     = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Draft',
            self::Processing => 'Processing',
            self::Computed   => 'Computed',
            self::Approved   => 'Approved',
            self::Finalized  => 'Finalized',
            self::Disbursed  => 'Disbursed',
            self::Voided     => 'Voided',
        };
    }

    /**
     * A locked period's payroll rows are immutable — no compute, no recompute.
     *
     * Disbursed is included because money has already left the bank; Voided is
     * included because its GL posting was reversed and the run is closed. Both
     * were previously recomputable through PayrollCalculatorService, which only
     * checked for Finalized.
     */
    public function isLocked(): bool
    {
        return in_array($this, [self::Finalized, self::Disbursed, self::Voided], true);
    }

    /**
     * May a compute run be started/restarted from this status?
     *
     * Draft    = never computed yet.
     * Computed = has rows awaiting approval; recomputing replaces them.
     *
     * Everything else is refused: Processing (a worker already owns it),
     * Approved (a checker has signed off — void or unlock first), and the
     * locked states above.
     */
    public function isComputable(): bool
    {
        return $this === self::Draft || $this === self::Computed;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
