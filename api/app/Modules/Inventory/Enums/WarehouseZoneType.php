<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum WarehouseZoneType: string
{
    case RawMaterials  = 'raw_materials';
    case Staging       = 'staging';
    case FinishedGoods = 'finished_goods';
    case SpareParts    = 'spare_parts';
    case Quarantine    = 'quarantine';
    case Scrap         = 'scrap';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::RawMaterials  => 'Raw Materials',
            self::Staging       => 'Staging',
            self::FinishedGoods => 'Finished Goods',
            self::SpareParts    => 'Spare Parts',
            self::Quarantine    => 'Quarantine',
            self::Scrap         => 'Scrap',
        };
    }
}
