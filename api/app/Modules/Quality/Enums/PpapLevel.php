<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/** IATF 16949 PPAP submission levels (1–5). */
enum PpapLevel: string
{
    case Level1 = '1';
    case Level2 = '2';
    case Level3 = '3';
    case Level4 = '4';
    case Level5 = '5';

    public function label(): string
    {
        return match ($this) {
            self::Level1 => 'Level 1 — Part Submission Warrant only',
            self::Level2 => 'Level 2 — PSW + limited supporting data',
            self::Level3 => 'Level 3 — PSW + full supporting data',
            self::Level4 => 'Level 4 — PSW + customer-specific requirements',
            self::Level5 => 'Level 5 — Full PPAP reviewed at supplier',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
