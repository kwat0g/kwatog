<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum SupplierQuoteStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Superseded = 'superseded';
    case Withdrawn = 'withdrawn';
    case Awarded = 'awarded';
    case NotAwarded = 'not_awarded';
    case Disqualified = 'disqualified';
}
