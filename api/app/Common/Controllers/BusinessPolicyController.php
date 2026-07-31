<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Services\BusinessPolicyService;
use App\Common\Services\TaxPolicyService;
use Illuminate\Http\JsonResponse;

class BusinessPolicyController
{
    public function __invoke(BusinessPolicyService $business, TaxPolicyService $tax): JsonResponse
    {
        return response()->json(['data' => [
            ...$business->defaults(),
            'vat_rate' => $tax->vatRate(),
        ]]);
    }
}
