<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Resources\QuoteResource;
use App\Modules\CRM\Resources\SalesOrderResource;
use App\Modules\CRM\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class QuoteController
{
    public function __construct(private readonly QuoteService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return QuoteResource::collection($this->service->list($request->query()));
    }

    public function show(Quote $quote): QuoteResource
    {
        return new QuoteResource($this->service->show($quote));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id'    => ['required', 'integer', 'exists:customers,id'],
            'opportunity_id' => ['nullable', 'integer', 'exists:opportunities,id'],
            'valid_until'    => ['nullable', 'date'],
            'terms'          => ['nullable', 'string'],
            'items'          => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        $quote = $this->service->create($data);
        return (new QuoteResource($quote))->response()->setStatusCode(201);
    }

    public function update(Request $request, Quote $quote): QuoteResource|JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'valid_until' => ['nullable', 'date'],
            'terms'       => ['nullable', 'string'],
            'items'       => ['sometimes', 'array', 'min:1'],
            'items.*.product_id'  => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.quantity'    => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.unit_price'  => ['required_with:items', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $quote = $this->service->update($quote, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new QuoteResource($quote);
    }

    public function send(Quote $quote): QuoteResource|JsonResponse
    {
        try {
            $quote = $this->service->send($quote);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new QuoteResource($quote);
    }

    public function accept(Quote $quote): QuoteResource|JsonResponse
    {
        try {
            $quote = $this->service->accept($quote);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new QuoteResource($quote);
    }

    public function reject(Quote $quote): QuoteResource|JsonResponse
    {
        try {
            $quote = $this->service->reject($quote);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new QuoteResource($quote);
    }

    /**
     * Convert a Quote into a SalesOrder (the pipeline terminus).
     * Returns the new SalesOrder resource so the frontend can redirect to it.
     */
    public function convert(Quote $quote, Request $request): SalesOrderResource|JsonResponse
    {
        try {
            $salesOrder = $this->service->convertToSalesOrder($quote, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\App\Modules\CRM\Exceptions\NoPriceAgreementException $e) {
            return response()->json([
                'message' => 'Cannot convert: one or more products lack an active price agreement for this customer.',
                'detail'  => $e->getMessage(),
            ], 422);
        }
        return new SalesOrderResource($salesOrder);
    }
}
