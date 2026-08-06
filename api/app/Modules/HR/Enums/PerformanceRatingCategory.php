<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum PerformanceRatingCategory: string
{
    case JobKnowledge = 'job_knowledge';
    case WorkQuality = 'work_quality';
    case Productivity = 'productivity';
    case Communication = 'communication';
    case Teamwork = 'teamwork';
    case Initiative = 'initiative';
    case Attendance = 'attendance';

    public function label(): string
    {
        return match ($this) {
            self::JobKnowledge => 'Job Knowledge',
            self::WorkQuality => 'Work Quality',
            self::Productivity => 'Productivity',
            self::Communication => 'Communication',
            self::Teamwork => 'Teamwork',
            self::Initiative => 'Initiative',
            self::Attendance => 'Attendance & Punctuality',
        };
    }
}
