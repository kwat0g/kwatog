<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Opportunity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LeadService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Lead::query()
            ->with(['assignee:id,name', 'customer:id,name']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['source'])) {
            $q->where('source', $filters['source']);
        }
        if (! empty($filters['assigned_to'])) {
            $uid = HashIdFilter::decode($filters['assigned_to'], User::class);
            if ($uid) $q->where('assigned_to', $uid);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('company_name', SearchOperator::like(), "%{$term}%")
                   ->orWhere('contact_person', SearchOperator::like(), "%{$term}%")
                   ->orWhere('lead_number', SearchOperator::like(), "%{$term}%");
            });
        }

        return $q->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Lead $lead): Lead
    {
        return $lead->load(['assignee:id,name', 'customer:id,name']);
    }

    public function create(array $data): Lead
    {
        return DB::transaction(function () use ($data) {
            $lead = Lead::create(array_merge($data, [
                'lead_number' => $this->sequences->generate('lead'),
                'status'      => LeadStatus::New->value,
            ]));
            return $this->show($lead->fresh());
        });
    }

    public function update(Lead $lead, array $data): Lead
    {
        if ($lead->status === LeadStatus::Converted) {
            throw new RuntimeException('Cannot update a converted lead.');
        }
        $lead->update($data);
        return $this->show($lead->fresh());
    }

    public function qualify(Lead $lead): Lead
    {
        if (! in_array($lead->status, [LeadStatus::New, LeadStatus::Contacted], true)) {
            throw new RuntimeException('Only new or contacted leads can be qualified.');
        }
        $lead->status = LeadStatus::Qualified;
        $lead->save();
        return $this->show($lead->fresh());
    }

    public function disqualify(Lead $lead, ?string $reason = null): Lead
    {
        if ($lead->status === LeadStatus::Converted) {
            throw new RuntimeException('Cannot disqualify a converted lead.');
        }
        $lead->notes = trim(($lead->notes ?? '') . ($reason ? "\n\n[Disqualified: {$reason}]" : "\n\n[Disqualified]"));
        $lead->status = LeadStatus::Disqualified;
        $lead->save();
        return $this->show($lead->fresh());
    }

    /**
     * Convert a qualified lead to an Opportunity. Wired once Opportunity model exists.
     * Sets lead status → converted and populates converted_to_opportunity_id.
     */
    public function convertToOpportunity(Lead $lead, OpportunityService $opportunityService): Opportunity
    {
        if ($lead->status !== LeadStatus::Qualified) {
            throw new RuntimeException('Only qualified leads can be converted to an opportunity.');
        }
        if ($lead->converted_to_opportunity_id) {
            throw new RuntimeException('This lead has already been converted.');
        }

        return DB::transaction(function () use ($lead, $opportunityService) {
            $opportunity = $opportunityService->createFromLead($lead);

            $lead->converted_to_opportunity_id = $opportunity->id;
            $lead->status = LeadStatus::Converted;
            $lead->save();

            return $opportunity;
        });
    }
}
