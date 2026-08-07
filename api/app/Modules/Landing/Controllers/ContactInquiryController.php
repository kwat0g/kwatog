<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Modules\Landing\Requests\StoreContactInquiryRequest;
use App\Modules\Landing\Services\ContactInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ContactInquiryController extends Controller
{
    public function __construct(private readonly ContactInquiryService $service) {}

    public function store(StoreContactInquiryRequest $request): JsonResponse
    {
        $this->service->create($request->validated(), $request);

        // No resource body: the sender is anonymous and has no business reading
        // back the stored row (or its id).
        return response()->json(
            ['message' => 'Thank you for reaching out. We have received your message and will get back to you shortly.'],
            201
        );
    }
}
