<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\DocumentAcknowledgment;
use App\Modules\Quality\Models\DocumentRevision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * T3.5.C — Self-service surface for document acknowledgments.
 *
 * pending()              — list pending receipts for the session user.
 * acknowledgeForRevision — flip a pending row to acknowledged. Idempotent.
 *                           Throws 403 if the caller has no ack row for the
 *                           revision (we don't leak revision existence).
 * acknowledge()          — back-compat helper that takes an ack row + user
 *                           and asserts the user owns it.
 */
class DocumentAcknowledgmentService
{
    /**
     * @return Collection<int, DocumentAcknowledgment>
     */
    public function pending(User $user): Collection
    {
        return DocumentAcknowledgment::query()
            ->with(['revision.document'])
            ->where('user_id', $user->id)
            ->whereNull('acknowledged_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Find the caller's ack row for a revision and flip it. Idempotent —
     * a second call on an already-acked row returns the row unchanged.
     */
    public function acknowledgeForRevision(DocumentRevision $revision, User $user): DocumentAcknowledgment
    {
        $ack = DocumentAcknowledgment::query()
            ->where('document_revision_id', $revision->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $ack) {
            // No ack row for this caller on this revision — treat as 403,
            // not 404. We don't leak whether the revision exists.
            throw new AccessDeniedHttpException('You do not have an acknowledgment for this revision.');
        }

        return $this->acknowledge($ack, $user);
    }

    /**
     * Flip an ack row to acknowledged. Asserts the caller owns the row.
     * Idempotent: re-acking an already-acked row returns it unchanged.
     */
    public function acknowledge(DocumentAcknowledgment $ack, User $user): DocumentAcknowledgment
    {
        if ($ack->user_id !== $user->id) {
            throw new AccessDeniedHttpException('You do not own this acknowledgment.');
        }

        if ($ack->acknowledged_at !== null) {
            return $ack->load(['revision.document']);
        }

        return DB::transaction(function () use ($ack) {
            $ack->forceFill(['acknowledged_at' => now()])->save();
            return $ack->fresh(['revision.document']);
        });
    }
}
