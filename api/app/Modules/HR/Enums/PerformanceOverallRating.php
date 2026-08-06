<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum PerformanceOverallRating: string
{
    case Outstanding = 'Outstanding';
    case ExceedsExpectations = 'Exceeds Expectations';
    case MeetsExpectations = 'Meets Expectations';
    case NeedsImprovement = 'Needs Improvement';
    case Unsatisfactory = 'Unsatisfactory';

    public function label(): string
    {
        return $this->value;
    }

    public static function values(): array
    {
        return array_map(static fn (self $rating): string => $rating->value, self::cases());
    }
}
