<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum RfqStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case UnderEvaluation = 'under_evaluation';
    case Awarded = 'awarded';
    case PartiallyAwarded = 'partially_awarded';
    case NoAward = 'no_award';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft', self::Open => 'Open', self::Closed => 'Closed',
            self::UnderEvaluation => 'Under evaluation', self::Awarded => 'Awarded',
            self::PartiallyAwarded => 'Partially awarded', self::NoAward => 'No award',
            self::Cancelled => 'Cancelled',
        };
    }

    public static function active(): array
    {
        return [self::Draft->value, self::Open->value, self::Closed->value, self::UnderEvaluation->value];
    }
}
