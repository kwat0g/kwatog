<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\OpportunityStage;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Models\Quote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OpportunityService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Opportunity::query()
            ->with(['customer:id,name', 'assignee:id,name']);

        if (! empty($filters['stage'])) {
            $q->where('stage', $filters['stage']);
        }
        if (! empty($filters['customer_id'])) {
            $cid = HashIdFilter::decode($filters['customer_id'], Customer::class);
            if ($cid) $q->where('customer_id', $cid);
        }
        if (! empty($filters['assigned_to'])) {
            $uid = HashIdFilter::decode($filters['assigned_to'], User::class);
            if ($uid) $q->where('assigned_to', $uid);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('title', SearchOperator::like(), "%{$term}%")
                   ->orWhere('opportunity_number', SearchOperator::like(), "%{$term}%");
            });
        }

        return $q->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Opportunity $opportunity): Opportunity
    {
        return $opportunity->load(['customer:id,name', 'assignee:id,name', 'lead:id,lead_number,company_name']);
    }

    public function create(array $data): Opportunity
    {
        return DB::transaction(function () use ($data) {
            $opportunity = Opportunity::create(array_merge($data, [
                'opportunity_number' => $this->sequences->generate('opportunity'),
            ]));
            return $this->show($opportunity->fresh());
        });
    }

    /**
     * Called by LeadService::convertToOpportunity — creates an Opportunity
     * seeded from the lead's data.
     */
    public function createFromLead(Lead $lead): Opportunity
    {
        return DB::transaction(function () use ($lead) {
            $opportunity = Opportunity::create([
                'opportunity_number' => $this->sequences->generate('opportunity'),
                'lead_id'            => $lead->id,
                'customer_id'        => $lead->customer_id ?? $this->resolveOrFailCustomer($lead),
                'title'              => $lead->company_name,
                'stage'              => OpportunityStage::Prospecting->value,
                'probability'        => 10,
                'estimated_value'    => $lead->estimated_value ?? '0.00',
                'assigned_to'        => $lead->assigned_to,
            ]);
            return $this->show($opportunity->fresh());
        });
    }

    private function resolveOrFailCustomer(Lead $lead): int
    {
        // Lead must have a customer_id to convert (or caller sets it explicitly).
        throw new RuntimeException(
            'Lead must be linked to a customer before conversion. Please set a customer on the lead first.'
        );
    }

    public function update(Opportunity $opportunity, array $data): Opportunity
    {
        if ($opportunity->stage->isTerminal()) {
            throw new RuntimeException('Cannot update a won or lost opportunity.');
        }
        $opportunity->update($data);
        return $this->show($opportunity->fresh());
    }

    /**
     * Advance stage to the next in the pipeline order.
     */
    public function advanceStage(Opportunity $opportunity): Opportunity
    {
        if ($opportunity->stage->isTerminal()) {
            throw new RuntimeException('Cannot advance a terminal opportunity stage.');
        }

        $order = OpportunityStage::advanceOrder();
        $current = $opportunity->stage;
        $idx = array_search($current, $order, true);

        if ($idx === false || $idx >= count($order) - 1) {
            throw new RuntimeException("Opportunity is already at the last advanceable stage ({$current->value}). Use win/lose to close it.");
        }

        $next = $order[$idx + 1];
        $opportunity->stage = $next;
        $opportunity->save();

        return $this->show($opportunity->fresh());
    }

    public function markWon(Opportunity $opportunity): Opportunity
    {
        if ($opportunity->stage->isTerminal()) {
            throw new RuntimeException('Opportunity is already closed.');
        }
        return DB::transaction(function () use ($opportunity) {
            $opportunity->stage = OpportunityStage::Won;
            $opportunity->actual_close_date = now()->toDateString();
            $opportunity->probability = 100;
            $opportunity->save();
            return $this->show($opportunity->fresh());
        });
    }

    public function markLost(Opportunity $opportunity, string $reason): Opportunity
    {
        if ($opportunity->stage->isTerminal()) {
            throw new RuntimeException('Opportunity is already closed.');
        }
        return DB::transaction(function () use ($opportunity, $reason) {
            $opportunity->stage = OpportunityStage::Lost;
            $opportunity->actual_close_date = now()->toDateString();
            $opportunity->lost_reason = $reason;
            $opportunity->probability = 0;
            $opportunity->save();
            return $this->show($opportunity->fresh());
        });
    }

    /**
     * Create a Quote from this Opportunity. Wired in Commit 3 once QuoteService exists.
     */
    public function createQuote(Opportunity $opportunity, QuoteService $quoteService): Quote
    {
        return $quoteService->createFromOpportunity($opportunity);
    }
}
