<?php

declare(strict_types=1);

namespace App\Modules\Landing\Services;

use App\Common\Support\SearchOperator;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Models\ContactInquiry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The ERP-side reader for public contact submissions.
 *
 * Separate from `ContactInquiryService`, which is the public write path: the two
 * have different callers, different auth posture, and no shared logic.
 */
class ContactInquiryInboxService
{
    /** @param array<string, mixed> $filters */
    public function list(array $filters): LengthAwarePaginator
    {
        $q = ContactInquiry::query();

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
        return $inquiry;
    }

    public function updateStatus(ContactInquiry $inquiry, ContactInquiryStatus $status): ContactInquiry
    {
        $inquiry->forceFill(['status' => $status->value])->save();

        return $this->show($inquiry);
    }
}
