<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\ControlledDocument;
use App\Modules\Quality\Models\DocumentAcknowledgment;
use App\Modules\Quality\Models\DocumentRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * T3.5 — Controlled-document service.
 *
 * Catalog responsibilities (Task 4):
 *   create() / update() / list() / show() / markReviewed()
 *
 * Revision/publish (Task 5): publishRevision() — multipart upload +
 * ack-row spawning for users holding the document's assignee_role.
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

    /**
     * Publish a new revision of a controlled document.
     *
     * Steps:
     *   1. Store the file on `local` disk BEFORE the transaction (mirrors
     *      DeliveryService::uploadReceiptPhoto). If anything below throws,
     *      delete the file and re-raise.
     *   2. Inside DB::transaction(): flip the prior is_current=true row
     *      (if any) to false, then insert the new revision row with
     *      is_current=true and revision_number = max+1.
     *   3. Stamp the parent doc's last_reviewed_at = now() and clear any
     *      pending review alert — publishing IS a review event.
     *   4. Bulk-insert document_acknowledgments rows for every active user
     *      whose role.slug == doc.assignee_role, chunked at 100.
     */
    public function publishRevision(
        ControlledDocument $doc,
        array $data,
        UploadedFile $file,
        ?User $publisher = null,
    ): DocumentRevision {
        $path = $file->store("controlled-documents/{$doc->id}", 'local');

        try {
            return DB::transaction(function () use ($doc, $data, $file, $path, $publisher) {
                // Zero out prior currents within the same tx.
                $doc->revisions()->where('is_current', true)
                    ->update(['is_current' => false]);

                $next = (int) ($doc->revisions()->max('revision_number') ?? 0) + 1;

                $rev = DocumentRevision::create([
                    'document_id'     => $doc->id,
                    'revision_number' => $next,
                    'effective_date'  => $data['effective_date'],
                    'change_reason'   => $data['change_reason'],
                    'file_path'       => $path,
                    'file_name'       => $file->getClientOriginalName(),
                    'file_size'       => $file->getSize(),
                    'mime_type'       => $file->getClientMimeType(),
                    'published_at'    => now(),
                    'published_by'    => $publisher?->id,
                    'is_current'      => true,
                ]);

                // Publishing is a review event — re-stamp the parent doc.
                $doc->forceFill([
                    'last_reviewed_at'     => now(),
                    'last_review_alert_at' => null,
                ])->save();

                $this->spawnAcknowledgments($doc, $rev);

                return $rev->fresh(['publisher']);
            });
        } catch (Throwable $e) {
            // Roll back the orphaned file before bubbling the exception.
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /**
     * Bulk-insert pending ack rows for every active user holding the
     * document's assignee_role. Chunks at 100 to keep statements small.
     */
    private function spawnAcknowledgments(ControlledDocument $doc, DocumentRevision $rev): void
    {
        $userIds = User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', $doc->assignee_role))
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        $now = now();
        $userIds->chunk(100)->each(function ($chunk) use ($rev, $now) {
            $rows = $chunk->map(fn ($uid) => [
                'document_revision_id' => $rev->id,
                'user_id'              => $uid,
                'acknowledged_at'      => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ])->all();
            DocumentAcknowledgment::insert($rows);
        });
    }
}
