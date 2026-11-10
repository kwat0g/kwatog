<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Requests\Customer\StoreCustomerOrderFeedbackRequest;
use App\Modules\B2B\Services\CustomerPortalService;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class CustomerOrderFeedbackController extends Controller
{
    public function __construct(private readonly CustomerPortalService $service) {}

    public function store(StoreCustomerOrderFeedbackRequest $request, SalesOrder $salesOrder): JsonResponse
    {
        /** @var CustomerPortalUser $portalUser */
        $portalUser = $request->user('customer_portal');
        $feedback = $this->service->submitOrderFeedback(
            $salesOrder,
            $portalUser,
            $request->validated(),
        );

        return response()->json([
            'data' => [
                'can_submit' => false,
                'submitted' => true,
                'submitted_at' => $feedback->created_at?->toIso8601String(),
            ],
            'message' => 'Thank you for sharing feedback.',
        ], 201);
    }
}
