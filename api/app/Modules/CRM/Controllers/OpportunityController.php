<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Resources\OpportunityResource;
use App\Modules\CRM\Resources\QuoteResource;
use App\Modules\CRM\Services\OpportunityService;
use App\Modules\CRM\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class OpportunityController
{
    public function __construct(
        private readonly OpportunityService $service,
        private readonly QuoteService $quoteService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return OpportunityResource::collection($this->service->list($request->query()));
    }

    public function show(Opportunity $opportunity): OpportunityResource
    {
        return new OpportunityResource($this->service->show($opportunity));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id'         => ['required', 'integer', 'exists:customers,id'],
            'lead_id'             => ['nullable', 'integer', 'exists:leads,id'],
            'title'               => ['required', 'string', 'max:255'],
            'stage'               => ['nullable', 'string', 'in:prospecting,needs_analysis,proposal,negotiation,won,lost'],
            'probability'         => ['nullable', 'integer', 'min:0', 'max:100'],
            'estimated_value'     => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'assigned_to'         => ['nullable', 'integer', 'exists:users,id'],
            'notes'               => ['nullable', 'string'],
        ]);

        $opportunity = $this->service->create($data);
        return (new OpportunityResource($opportunity))->response()->setStatusCode(201);
    }

    public function update(Request $request, Opportunity $opportunity): OpportunityResource|JsonResponse
    {
        $data = $request->validate([
            'customer_id'         => ['sometimes', 'integer', 'exists:customers,id'],
            'title'               => ['sometimes', 'string', 'max:255'],
            'probability'         => ['nullable', 'integer', 'min:0', 'max:100'],
            'estimated_value'     => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'assigned_to'         => ['nullable', 'integer', 'exists:users,id'],
            'notes'               => ['nullable', 'string'],
        ]);

        try {
            $opportunity = $this->service->update($opportunity, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new OpportunityResource($opportunity);
    }

    public function advance(Opportunity $opportunity): OpportunityResource|JsonResponse
    {
        try {
            $opportunity = $this->service->advanceStage($opportunity);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new OpportunityResource($opportunity);
    }

    public function win(Opportunity $opportunity): OpportunityResource|JsonResponse
    {
        try {
            $opportunity = $this->service->markWon($opportunity);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new OpportunityResource($opportunity);
    }

    public function lose(Request $request, Opportunity $opportunity): OpportunityResource|JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $opportunity = $this->service->markLost($opportunity, $data['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new OpportunityResource($opportunity);
    }

    public function createQuote(Opportunity $opportunity): QuoteResource|JsonResponse
    {
        try {
            $quote = $this->service->createQuote($opportunity, $this->quoteService);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new QuoteResource($quote))->response()->setStatusCode(201);
    }
}
