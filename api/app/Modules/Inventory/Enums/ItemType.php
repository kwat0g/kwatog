<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum ItemType: string
{
    case RawMaterial   = 'raw_material';
    case FinishedGood  = 'finished_good';
    case Packaging     = 'packaging';
    case SparePart     = 'spare_part';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial   => 'Raw Material',
            self::FinishedGood  => 'Finished Good',
            self::Packaging     => 'Packaging',
            self::SparePart     => 'Spare Part',
        };
    }
}
