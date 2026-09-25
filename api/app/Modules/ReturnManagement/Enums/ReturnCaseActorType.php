<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum ReturnCaseActorType: string
{
    case Internal = 'internal';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case System = 'system';
}
