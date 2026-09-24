<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Mail\SupplierRfqLifecycleMail;
use App\Modules\Purchasing\Models\RequestForQuote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * RFQ notices (docs/SUPPLIER-RFQ-BIDDING-PLAN.md §8). Internal notices go to
 * the people who act on the RFQ — its buyer and the PR requester — not to
 * every RFQ viewer. Suppliers get an email per event that concerns them.
 */
final class DeliverRfqLifecycleNotification implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    private const SUPPLIER_KINDS = ['published', 'extended', 'awarded', 'cancelled'];

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(RfqLifecycleEvent $event): void
    {
        try {
            $rfq = RequestForQuote::query()
                ->with(['purchaseRequest:id,pr_number,requested_by', 'invitations.vendor:id,name,email,contact_person'])
                ->findOrFail($event->rfqId);

            $this->notifyInternal($rfq, $event->kind);
            // A draft that was cancelled never reached a supplier.
            if (in_array($event->kind, self::SUPPLIER_KINDS, true) && $rfq->issued_at !== null) {
                $this->deliverToSuppliers($rfq, $event->kind);
            }
        } catch (\Throwable $e) {
            Log::warning('RFQ lifecycle delivery failed', [
                'rfq_id' => $event->rfqId,
                'kind' => $event->kind,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function notifyInternal(RequestForQuote $rfq, string $kind): void
    {
        $copy = match ($kind) {
            'closed' => ['purchasing.rfq_closed', "RFQ {$rfq->rfq_number} is ready to award", 'Quotations are unsealed. Compare them and award each line.'],
            'awarded' => ['purchasing.rfq_awarded', "RFQ {$rfq->rfq_number} awarded", 'Draft purchase orders were created from the award. Submit them for approval.'],
            'cancelled' => ['purchasing.rfq_cancelled', "RFQ {$rfq->rfq_number} cancelled", $rfq->cancellation_reason ?? 'The RFQ was cancelled. The purchase request is available for Direct PO or a new RFQ.'],
            default => null,
        };
        if ($copy === null) {
            return;
        }
        // "Ready to award" is the buyer's task; outcomes also concern the requester.
        $audience = $this->activeUsers([(int) $rfq->created_by]);
        if ($kind !== 'closed' && $rfq->purchaseRequest?->requested_by) {
            $audience = $audience->merge($this->activeUsers([(int) $rfq->purchaseRequest->requested_by]));
        }
        if ($audience->isEmpty()) {
            // The buyer left; any active buyer can pick the task up.
            $audience = User::query()
                ->where('is_active', true)
                ->whereHas('role.permissions', fn ($query) => $query->where('slug', 'purchasing.rfq.manage'))
                ->get();
        }
        if ($audience->isEmpty()) {
            return;
        }

        [$type, $title, $message] = $copy;
        $this->notifications->send($audience->unique('id')->values(), $type, [
            'title' => $title,
            'message' => $message,
            'link_to' => '/purchasing/rfqs/'.$rfq->hash_id,
            'entity_type' => 'request_for_quote',
            'entity_id' => $rfq->hash_id,
        ]);
    }

    /** @param list<int> $ids */
    private function activeUsers(array $ids): Collection
    {
        return User::query()->whereKey(array_filter($ids))->where('is_active', true)->get();
    }

    private function deliverToSuppliers(RequestForQuote $rfq, string $kind): void
    {
        $fallback = app(EmailDeliveryFailureNotifier::class)->userIdsWithPermission('purchasing.rfq.manage');
        foreach ($rfq->invitations as $invitation) {
            $invitation->forceFill(['portal_notified_at' => now(), 'last_notification_error' => null])->save();
            $email = $invitation->vendor?->email;
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invitation->forceFill(['last_notification_error' => 'Supplier has no usable email address.'])->save();

                continue;
            }
            try {
                Mail::to($email)->queue(new SupplierRfqLifecycleMail($rfq, $invitation, $kind, $fallback));
                $invitation->forceFill(['email_notified_at' => now()])->save();
            } catch (\Throwable $e) {
                $invitation->forceFill(['last_notification_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
                Log::warning('RFQ supplier email could not be queued', ['rfq_id' => $rfq->id, 'invitation_id' => $invitation->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
