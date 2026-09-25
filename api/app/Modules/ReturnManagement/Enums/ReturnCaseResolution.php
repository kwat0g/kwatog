<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum ReturnCaseResolution: string
{
    case ReturnGoods = 'return_goods';
    case Redelivery = 'redelivery';
    case Credit = 'credit';
    case NoAction = 'no_action';

    public function label(): string
    {
        return match ($this) {
            self::ReturnGoods => 'Return Goods',
            self::Redelivery => 'Redelivery',
            self::Credit => 'Credit',
            self::NoAction => 'No Action',
        };
    }
}
