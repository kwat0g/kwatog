<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum PropertyStatus: string
{
    case Issued   = 'issued';
    case Returned = 'returned';
    case Lost     = 'lost';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
