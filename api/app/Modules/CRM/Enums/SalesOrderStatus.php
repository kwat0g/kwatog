<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum SalesOrderStatus: string
{
    case Draft               = 'draft';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case DeliveryNegotiation  = 'delivery_negotiation';
    case Confirmed           = 'confirmed';
    case InProduction        = 'in_production';
    case PartiallyDelivered  = 'partially_delivered';
    case Delivered           = 'delivered';
    case Invoiced            = 'invoiced';
    case Paid                = 'paid';
    case Closed              = 'closed';
    case Cancelled           = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isBeforeConfirmation(): bool
    {
        return in_array($this, [self::Draft, self::AwaitingConfirmation, self::DeliveryNegotiation], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft              => 'Draft',
            self::AwaitingConfirmation => 'Awaiting confirmation',
            self::DeliveryNegotiation => 'Delivery negotiation',
            self::Confirmed          => 'Confirmed',
            self::InProduction       => 'In Production',
            self::PartiallyDelivered => 'Partially Delivered',
            self::Delivered          => 'Delivered',
            self::Invoiced           => 'Invoiced',
            self::Paid               => 'Paid',
            self::Closed             => 'Closed',
            self::Cancelled          => 'Cancelled',
        };
    }
}
