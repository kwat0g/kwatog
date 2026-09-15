<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum SupplierQuoteResponseStatus: string
{
    case Quoted = 'quoted';
    case NoQuote = 'no_quote';
}
