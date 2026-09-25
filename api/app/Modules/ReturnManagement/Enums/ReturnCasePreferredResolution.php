<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum ReturnCasePreferredResolution: string
{
    case Redelivery = 'redelivery';
    case Credit = 'credit';
    case Advice = 'advice';

    public function label(): string
    {
        return match ($this) {
            self::Redelivery => 'Redelivery',
            self::Credit => 'Credit',
            self::Advice => 'Help me decide',
        };
    }
}
