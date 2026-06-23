<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum LeadSource: string
{
    case Referral         = 'referral';
    case Website          = 'website';
    case TradeShow        = 'trade_show';
    case ColdCall         = 'cold_call';
    case ExistingCustomer = 'existing_customer';
    case Other            = 'other';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Referral         => 'Referral',
            self::Website          => 'Website',
            self::TradeShow        => 'Trade Show',
            self::ColdCall         => 'Cold Call',
            self::ExistingCustomer => 'Existing Customer',
            self::Other            => 'Other',
        };
    }
}
