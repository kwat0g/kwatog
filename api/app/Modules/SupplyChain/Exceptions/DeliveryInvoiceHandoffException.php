<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Exceptions;

use RuntimeException;

/**
 * A delivery is confirmed, but its draft AR invoice needs data or
 * configuration that Finance must correct before the handoff can complete.
 */
class DeliveryInvoiceHandoffException extends RuntimeException
{
}
