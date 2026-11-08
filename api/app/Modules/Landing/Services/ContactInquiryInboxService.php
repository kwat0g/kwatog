<?php

declare(strict_types=1);

namespace App\Modules\Landing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Exceptions\ContactInquiryStateConflictException;
use App\Modules\Landing\Models\ContactInquiry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The ERP-side reader for public contact submissions.
 *
 * Separate from `ContactInquiryService`, which is the public write path: the two
 * have different callers, different auth posture, and no shared logic.
 */
class ContactInquiryInboxService
{
    public function __construct(private readonly ContactInquiryRealtimeService $realtime) {}

    /** @param array<string, mixed> $filters */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        return $this->listQuery($filters)->orderByDesc('id')->paginate($perPage);
    }

    /** @param array<string, mixed> $filters */
    public function listQuery(array $filters): Builder
    {
        $q = ContactInquiry::query();
        if (($filters['status'] ?? null) === ContactInquiryStatus::New->value) {
            $q->awaitingTriage();
        } elseif (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term): void {
                $qq->where('full_name', SearchOperator::like(), SearchOperator::contains($term))
                    ->orWhere('company', SearchOperator::like(), SearchOperator::contains($term))
                    ->orWhere('email', SearchOperator::like(), SearchOperator::contains($term))
                    ->orWhere('inquiry_no', SearchOperator::like(), SearchOperator::contains($term));
            });
        }

        return $q;
    }

    public function show(ContactInquiry $inquiry): ContactInquiry
    {
        return $inquiry;
    }

    public function updateStatus(
        ContactInquiry $inquiry,
        ContactInquiryStatus $status,
        ?ContactInquiryStatus $expectedStatus = null,
    ): ContactInquiry {
        return DB::transaction(function () use ($inquiry, $status, $expectedStatus): ContactInquiry {
            $locked = ContactInquiry::query()->lockForUpdate()->findOrFail($inquiry->id);
            $current = $locked->status;

            if ($expectedStatus !== null && $current !== $expectedStatus) {
                throw new ContactInquiryStateConflictException(
                    "This contact inquiry changed from {$expectedStatus->value} to {$current->value}. Refresh and review the current status.",
                );
            }

            if ($current === $status) {
                return $locked;
            }

            $allowed = match ($current) {
                ContactInquiryStatus::New => [ContactInquiryStatus::InProgress, ContactInquiryStatus::Closed],
                ContactInquiryStatus::InProgress => [ContactInquiryStatus::Closed],
                ContactInquiryStatus::Closed => [],
            };
            if (! in_array($status, $allowed, true)) {
                throw new BusinessRuleException(
                    "A {$current->value} contact inquiry cannot transition to {$status->value}.",
                );
            }

            $locked->forceFill(['status' => $status])->save();
            $actor = auth()->user();
            $this->realtime->stage(
                'status_changed',
                $actor instanceof User ? $actor : null,
                (string) $locked->hash_id,
            );

            return $this->show($locked->fresh());
        });
    }
}
