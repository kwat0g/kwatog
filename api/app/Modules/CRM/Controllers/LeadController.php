<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Resources\LeadResource;
use App\Modules\CRM\Resources\OpportunityResource;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\OpportunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name'    => ['required', 'string', 'max:255'],
            'contact_person'  => ['required', 'string', 'max:255'],
            'email'           => ['nullable', 'email', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'source'          => ['required', 'string', 'in:referral,website,trade_show,cold_call,existing_customer,other'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string'],
            'assigned_to'     => ['nullable', 'integer', 'exists:users,id'],
            'customer_id'     => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        $lead = $this->service->create($data);
        return (new LeadResource($lead))->response()->setStatusCode(201);
    }

    public function update(Request $request, Lead $lead): LeadResource|JsonResponse
    {
        $data = $request->validate([
            'company_name'    => ['sometimes', 'string', 'max:255'],
            'contact_person'  => ['sometimes', 'string', 'max:255'],
            'email'           => ['nullable', 'email', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'source'          => ['sometimes', 'string', 'in:referral,website,trade_show,cold_call,existing_customer,other'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string'],
            'assigned_to'     => ['nullable', 'integer', 'exists:users,id'],
            'customer_id'     => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        try {
            $lead = $this->service->update($lead, $data);
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
