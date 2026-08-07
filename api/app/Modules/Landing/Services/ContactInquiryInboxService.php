<?php

declare(strict_types=1);

namespace App\Modules\Landing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\SearchOperator;
use App\Modules\CRM\Enums\LeadSource;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Models\ContactInquiry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The ERP-side reader for public contact submissions.
 *
 * Separate from `ContactInquiryService`, which is the public write path: the two
 * have different callers, different auth posture, and no shared logic.
 */
class ContactInquiryInboxService
{
    public function __construct(private readonly LeadService $leads) {}

    /** @param array<string, mixed> $filters */
    public function list(array $filters): LengthAwarePaginator
    {
        $q = ContactInquiry::query()->with('convertedToLead:id,lead_number');

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term): void {
                $qq->where('full_name', SearchOperator::like(), "%{$term}%")
                    ->orWhere('company', SearchOperator::like(), "%{$term}%")
                    ->orWhere('email', SearchOperator::like(), "%{$term}%")
                    ->orWhere('inquiry_no', SearchOperator::like(), "%{$term}%");
            });
        }

        return $q->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(ContactInquiry $inquiry): ContactInquiry
    {
        return $inquiry->load('convertedToLead:id,lead_number');
    }

    public function updateStatus(ContactInquiry $inquiry, ContactInquiryStatus $status): ContactInquiry
    {
        // Converted is a consequence of the convert action, not a label an
        // operator can apply — setting it by hand would claim a lead exists.
        if ($status === ContactInquiryStatus::Converted) {
            throw new BusinessRuleException('Use the convert action to mark an inquiry as converted.');
        }

        if ($inquiry->status === ContactInquiryStatus::Converted) {
            throw new BusinessRuleException('This inquiry has already been converted to a lead.');
        }

        $inquiry->forceFill(['status' => $status->value])->save();

        return $this->show($inquiry);
    }

    /**
     * Promote an inquiry into the CRM funnel.
     *
     * The deliberate gate between a contact form and the pipeline: the form also
     * catches job seekers, supplier pitches and general questions, and routing
     * those in automatically would pollute the funnel.
     */
    public function convertToLead(ContactInquiry $inquiry): Lead
    {
        if ($inquiry->status === ContactInquiryStatus::Converted) {
            throw new BusinessRuleException('This inquiry has already been converted to a lead.');
        }

        return DB::transaction(function () use ($inquiry): Lead {
            $lead = $this->leads->create([
                // Falls back to the sender's name: `company_name` is required on
                // a lead, and an individual enquiring is their own company.
                'company_name' => $inquiry->company ?: $inquiry->full_name,
                'contact_person' => $inquiry->full_name,
                'email' => $inquiry->email,
                'phone' => $inquiry->phone,
                'source' => LeadSource::Website->value,
                'notes' => $inquiry->message,
            ]);

            $inquiry->forceFill([
                'status' => ContactInquiryStatus::Converted->value,
                'converted_to_lead_id' => $lead->id,
            ])->save();

            return $lead;
        });
    }
}
