<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Requests\StoreLeadRequest;
use App\Modules\CRM\Requests\UpdateLeadRequest;
use App\Modules\CRM\Resources\LeadResource;
use App\Modules\CRM\Resources\OpportunityResource;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\OpportunityService;
use App\Modules\CRM\Enums\LeadSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Illuminate\Validation\Rule;

class LeadController
{
    public function __construct(
        private readonly LeadService $service,
        private readonly OpportunityService $opportunityService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LeadResource::collection($this->service->list($request->query()));
    }

    public function show(Lead $lead): LeadResource
    {
        return new LeadResource($this->service->show($lead));
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $lead = $this->service->create($request->validated());
        return (new LeadResource($lead))->response()->setStatusCode(201);
    }

    public function update(UpdateLeadRequest $request, Lead $lead): LeadResource|JsonResponse
    {
        try {
            $lead = $this->service->update($lead, $request->validated());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new LeadResource($lead);
    }

    public function qualify(Lead $lead): LeadResource|JsonResponse
    {
        try {
            $lead = $this->service->qualify($lead);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new LeadResource($lead);
    }

    public function disqualify(Request $request, Lead $lead): LeadResource|JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $lead = $this->service->disqualify($lead, $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new LeadResource($lead);
    }

    public function convert(Lead $lead): OpportunityResource|JsonResponse
    {
        try {
            $opportunity = $this->service->convertToOpportunity($lead, $this->opportunityService);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new OpportunityResource($opportunity);
    }
}
