<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum BankFileFormat: string
{
    case Generic = 'generic';
    case Bdo = 'bdo';
    case Bpi = 'bpi';
    case Metrobank = 'metrobank';
}
