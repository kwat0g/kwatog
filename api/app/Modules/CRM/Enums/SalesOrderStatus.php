<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum SalesOrderStatus: string
{
    case Draft               = 'draft';
    case Confirmed           = 'confirmed';
    case InProduction        = 'in_production';
    case PartiallyDelivered  = 'partially_delivered';
    case Delivered           = 'delivered';
    case Invoiced            = 'invoiced';
    case Cancelled           = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft              => 'Draft',
            self::Confirmed          => 'Confirmed',
            self::InProduction       => 'In Production',
            self::PartiallyDelivered => 'Partially Delivered',
            self::Delivered          => 'Delivered',
            self::Invoiced           => 'Invoiced',
            self::Cancelled          => 'Cancelled',
        };
    }
}
