<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Enums\ReviewCycleStatus;
use App\Modules\HR\Enums\ReviewStatus;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use App\Modules\HR\Models\ReviewTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PerformanceReviewService
{
    public function listCycles(array $filters): LengthAwarePaginator
    {
        $q = ReviewCycle::query()->withCount('reviews');

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->orderByDesc('start_date')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function createCycle(array $data): ReviewCycle
    {
        return DB::transaction(function () use ($data) {
            $cycle = new ReviewCycle();
            $cycle->fill($data);
            $cycle->status = ReviewCycleStatus::Draft;
            $cycle->created_by = Auth::id();
            $cycle->save();
            return $cycle;
        });
    }

    public function activateCycle(ReviewCycle $cycle): ReviewCycle
    {
        if ($cycle->status !== ReviewCycleStatus::Draft) {
            throw new RuntimeException('Only draft cycles can be activated.');
        }

        $cycle->forceFill(['status' => ReviewCycleStatus::Active->value])->save();
        return $cycle;
    }

    public function closeCycle(ReviewCycle $cycle): ReviewCycle
    {
        if ($cycle->status !== ReviewCycleStatus::Active) {
            throw new RuntimeException('Only active cycles can be closed.');
        }

        $cycle->forceFill(['status' => ReviewCycleStatus::Closed->value])->save();
        return $cycle;
    }

    public function listReviews(array $filters): LengthAwarePaginator
    {
        $q = PerformanceReview::query()
            ->with(['employee:id,first_name,last_name', 'reviewer:id,first_name,last_name', 'cycle:id,name']);

        if (! empty($filters['review_cycle_id'])) {
            $q->where('review_cycle_id', $filters['review_cycle_id']);
        }
        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function createReview(array $data): PerformanceReview
    {
        return DB::transaction(function () use ($data) {
            $review = new PerformanceReview();
            $review->fill($data);
            $review->status = ReviewStatus::Pending;
            $review->save();
            return $review->fresh(['employee:id,first_name,last_name', 'reviewer:id,first_name,last_name']);
        });
    }

    public function submitReview(PerformanceReview $review, array $data): PerformanceReview
    {
        if (! in_array($review->status, [ReviewStatus::Pending, ReviewStatus::InProgress])) {
            throw new RuntimeException('Review already submitted.');
        }

        return DB::transaction(function () use ($review, $data) {
            $review->fill($data);
            $review->forceFill([
                'status'       => ReviewStatus::Submitted->value,
                'submitted_at' => now(),
            ])->save();

            return $review->fresh();
        });
    }

    public function acknowledge(PerformanceReview $review): PerformanceReview
    {
        if ($review->status !== ReviewStatus::Submitted) {
            throw new RuntimeException('Only submitted reviews can be acknowledged.');
        }

        $review->forceFill([
            'status'          => ReviewStatus::Acknowledged->value,
            'acknowledged_at' => now(),
        ])->save();

        return $review->fresh();
    }

    public function listTemplates(): LengthAwarePaginator
    {
        return ReviewTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(50);
    }

    public function createTemplate(array $data): ReviewTemplate
    {
        return ReviewTemplate::create($data);
    }
}
