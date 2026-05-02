<?php

declare(strict_types=1);

namespace App\Modules\CRM\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * Thrown by PriceAgreementService::resolve() when no active agreement exists
 * for a (customer, product, date). Renders as a 422 with a field-targeted
 * error so the SO create form can highlight the offending line item.
 */
class NoPriceAgreementException extends HttpResponseException
{
    public function __construct(string $field = 'product_id')
    {
        parent::__construct(new JsonResponse([
            'message' => 'No active price agreement for this customer and product.',
            'errors'  => [
                $field => ['No active price agreement for this customer and product on the selected delivery date.'],
            ],
        ], 422));
    }
}
