<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum ApplicationStage: string
{
    case New       = 'new';
    case Screening = 'screening';
    case Interview = 'interview';
    case Offer     = 'offer';
    case Hired     = 'hired';
    case Rejected  = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::New       => 'New',
            self::Screening => 'Screening',
            self::Interview => 'Interview',
            self::Offer     => 'Offer',
            self::Hired     => 'Hired',
            self::Rejected  => 'Rejected',
        };
    }

    public function publicLabel(): string
    {
        return match ($this) {
            self::New, self::Screening => 'Application Received',
            self::Interview            => 'Under Review',
            self::Offer                => 'Offer Extended',
            self::Hired                => 'Hired',
            self::Rejected             => 'Not Selected',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Hired, self::Rejected], true);
    }

    public function next(): ?self
    {
        return match ($this) {
            self::New       => self::Screening,
            self::Screening => self::Interview,
            self::Interview => self::Offer,
            self::Offer     => self::Hired,
            default         => null,
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
