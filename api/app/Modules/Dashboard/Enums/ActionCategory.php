<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Enums;

enum ActionCategory: string
{
    case Approval = 'approval';
    case Alert = 'alert';
    case Quality = 'quality';
    case Maintenance = 'maintenance';
    case Production = 'production';
    case SupplyChain = 'supply_chain';

    public function label(): string
    {
        return match ($this) {
            self::Approval => 'Approvals',
            self::Alert => 'Alerts',
            self::Quality => 'Quality',
            self::Maintenance => 'Maintenance',
            self::Production => 'Production',
            self::SupplyChain => 'Supply chain',
        };
    }
}
