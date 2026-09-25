<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum DeliveryAttemptReason: string
{
    case CustomerRefused = 'customer_refused';
    case CustomerUnavailable = 'customer_unavailable';
    case AccessRestricted = 'access_restricted';
    case VehicleBreakdown = 'vehicle_breakdown';
    case RouteDisruption = 'route_disruption';
    case GoodsDamaged = 'goods_damaged';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerRefused => 'Customer refused delivery',
            self::CustomerUnavailable => 'Customer unavailable',
            self::AccessRestricted => 'Access restricted',
            self::VehicleBreakdown => 'Vehicle breakdown',
            self::RouteDisruption => 'Route disruption',
            self::GoodsDamaged => 'Goods damaged in transit',
            self::Other => 'Other delivery exception',
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(static fn (self $reason): array => [
            'value' => $reason->value,
            'label' => $reason->label(),
        ], self::cases());
    }
}
