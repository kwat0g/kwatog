<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Requests\StoreOpportunityRequest;
use App\Modules\CRM\Requests\UpdateOpportunityRequest;
use App\Modules\CRM\Resources\OpportunityResource;
use App\Modules\CRM\Resources\QuoteResource;
use App\Modules\CRM\Services\OpportunityService;
use App\Modules\CRM\Services\QuoteService;
use App\Modules\CRM\Enums\OpportunityStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Illuminate\Validation\Rule;
use App\Common\Services\SettingsService;

class OpportunityController
{
    public function __construct(
        private readonly OpportunityService $service,
        private readonly QuoteService $quoteService,
        private readonly SettingsService $settings,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'initial_probability' => $this->settings->requiredInt('crm.opportunity.initial_probability', 0, 100),
            'stages' => array_map(static fn (OpportunityStage $stage): array => [
                'value' => $stage->value, 'label' => $stage->label(),
            ], OpportunityStage::cases()),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return OpportunityResource::collection($this->service->list($request->query()));
    }

    public function show(Opportunity $opportunity): OpportunityResource
    {
        return new OpportunityResource($this->service->show($opportunity));
    }

    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $opportunity = $this->service->create($request->validated());
        return (new OpportunityResource($opportunity))->response()->setStatusCode(201);
    }

    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity): OpportunityResource|JsonResponse
    {
        try {
            $opportunity = $this->service->update($opportunity, $request->validated());
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
