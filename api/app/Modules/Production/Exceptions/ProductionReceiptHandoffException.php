<?php

declare(strict_types=1);

namespace App\Modules\Production\Exceptions;

use RuntimeException;

/** Expected product/location/setup gap in the production → inventory handoff. */
class ProductionReceiptHandoffException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode = 'inventory_setup_missing',
    ) {
        parent::__construct($message);
    }
}
