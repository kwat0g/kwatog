<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\RfqInvitationStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Models\RequestForQuote;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one RFQ row scope and action matrix. The RFQ resource renders
 * actionsFor() so the pages show exactly one next step per state, and every
 * service mutation re-checks the same rule under its row lock.
 */
class RequestForQuoteAccessPolicy
{
    /**
     * The ONE RFQ row scope: the RFQ list and global search both call it.
     * RFQ viewers and buyers see every RFQ; anyone else only the RFQs they
     * created or that source their own purchase request.
     */
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('purchasing.rfq.view') || $user->hasPermission('purchasing.rfq.manage')) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('request_for_quotes.created_by', $user->id)
            ->orWhereHas('purchaseRequest', fn (Builder $pr) => $pr->where('requested_by', $user->id)));
    }

    public function canSee(User $user, RequestForQuote $rfq): bool
    {
        return $user->hasPermission('purchasing.rfq.view')
            || $user->hasPermission('purchasing.rfq.manage')
            || (int) $rfq->created_by === (int) $user->id
            || $rfq->purchaseRequest()->where('requested_by', $user->id)->exists();
    }

    public function canManage(User $user): bool
    {
        return $user->hasPermission('purchasing.rfq.manage');
    }

    /** system_admin holds every permission by wildcard but is an IT role, not a buyer. */
    public function canAward(User $user): bool
    {
        return $this->canManage($user) && $user->role?->slug !== 'system_admin';
    }

    /** Every invited supplier has a submitted quote, so waiting for the deadline adds nothing. */
    public function allInvitedResponded(RequestForQuote $rfq): bool
    {
        $invitations = $rfq->relationLoaded('invitations') ? $rfq->invitations : $rfq->invitations()->get();

        return $invitations->isNotEmpty()
            && $invitations->every(fn ($invitation): bool => $invitation->status === RfqInvitationStatus::Submitted);
    }

    /** @return array<string, bool> */
    public function actionsFor(User $user, RequestForQuote $rfq): array
    {
        $manage = $this->canManage($user);
        $status = $rfq->status;
        $open = $status === RfqStatus::Open && $rfq->closes_at !== null && $rfq->closes_at->isFuture();

        return [
            'can_edit' => $manage && $status === RfqStatus::Draft,
            'can_publish' => $manage && $status === RfqStatus::Draft,
            'can_extend' => $manage && $status === RfqStatus::Open,
            'can_close_now' => $manage && $open && $this->allInvitedResponded($rfq),
            'can_cancel' => $manage && in_array($status, [RfqStatus::Draft, RfqStatus::Open, RfqStatus::Closed], true),
            'can_capture_quote' => $manage && $open,
            'can_upload_document' => $manage && in_array($status, [RfqStatus::Draft, RfqStatus::Open], true),
            'can_compare' => ($user->hasPermission('purchasing.rfq.view') || $manage)
                && in_array($status, [RfqStatus::Closed, RfqStatus::Awarded], true),
            'can_award' => $this->canAward($user) && $status === RfqStatus::Closed,
        ];
    }
}
