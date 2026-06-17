<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Modules\Landing\Requests\StoreQuoteRequestRequest;
use App\Modules\Landing\Services\QuoteRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class QuoteRequestController extends Controller
{
    public function __construct(private readonly QuoteRequestService $service) {}

    public function store(StoreQuoteRequestRequest $request): JsonResponse
    {
        $data = $request->safe()->except('drawing');

        $this->service->create($data, $request->file('drawing'), $request);

        return response()->json(
            ['message' => 'Your quote request has been received. Our engineers will reply within 1–2 business days.'],
            201
        );
    }
}
