<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\ControlledDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * T3.5 — Controlled-document service.
 *
 * Catalog responsibilities (Task 4):
 *   create() / update() / list() / show() / markReviewed()
 *
 * Revision/publish responsibilities (Task 5) live in publishRevision()
 * (added in a follow-up commit).
 */
class DocumentService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = ControlledDocument::query()->with(['currentRevision']);

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['category'])) {
            $q->where('category', $filters['category']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn ($b) => $b->where('code', 'ilike', $term)->orWhere('title', 'ilike', $term));
        }

        return $q->orderByDesc('updated_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(ControlledDocument $doc): ControlledDocument
    {
        return $doc->load(['currentRevision', 'currentRevision.publisher:id,name']);
    }

    public function create(array $data): ControlledDocument
    {
        return DB::transaction(fn () => ControlledDocument::create([
            'code'                   => $data['code'],
            'title'                  => $data['title'],
            'category'               => $data['category'],
            'description'            => $data['description'] ?? null,
            'assignee_role'          => $data['assignee_role'],
            'review_interval_months' => $data['review_interval_months'] ?? null,
            'is_active'              => $data['is_active'] ?? true,
        ]));
    }

    public function update(ControlledDocument $doc, array $data): ControlledDocument
    {
        return DB::transaction(function () use ($doc, $data) {
            $doc->fill(array_intersect_key($data, array_flip([
                'code', 'title', 'category', 'description',
                'assignee_role', 'review_interval_months', 'is_active',
            ])));
            $doc->save();
            return $doc->fresh(['currentRevision']);
        });
    }

    /**
     * Stamp last_reviewed_at = now() and clear last_review_alert_at.
     * Used for periodic-review checkpoints with no content change.
     */
    public function markReviewed(ControlledDocument $doc): ControlledDocument
    {
        return DB::transaction(function () use ($doc) {
            $doc->forceFill([
                'last_reviewed_at'     => now(),
                'last_review_alert_at' => null,
            ])->save();
            return $doc->fresh();
        });
    }
}
