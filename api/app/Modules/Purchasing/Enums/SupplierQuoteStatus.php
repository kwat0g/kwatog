<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

/**
 * One quote per supplier per RFQ. Draft and submitted flip back and forth
 * while the RFQ is open (withdraw returns a submitted quote to draft); only
 * submitted quotes are ever evaluated. Award resolves the submitted ones.
 */
enum SupplierQuoteStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Awarded = 'awarded';
    case NotAwarded = 'not_awarded';
}
