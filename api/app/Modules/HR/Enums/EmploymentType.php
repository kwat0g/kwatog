<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum EmploymentType: string
{
    case Regular      = 'regular';
    case Probationary = 'probationary';
    case Contractual  = 'contractual';
    case ProjectBased = 'project_based';

    public function label(): string
    {
        return match ($this) {
            self::Regular      => 'Regular',
            self::Probationary => 'Probationary',
            self::Contractual  => 'Contractual',
            self::ProjectBased => 'Project-Based',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
