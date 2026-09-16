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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class DeliverRfqLifecycleNotification implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(RfqLifecycleEvent $event): void
    {
        try {
            $rfq = RequestForQuote::query()
                ->with(['purchaseRequest:id,pr_number,requested_by', 'invitations.vendor:id,name,email'])
                ->findOrFail($event->rfqId);

            $this->notifyInternal($rfq, $event->kind);
            if (in_array($event->kind, [
                'published', 'extended', 'addendum', 'closed', 'awarded',
                'no_award', 'cancelled', 'quote_reconfirmation_required',
            ], true)) {
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
        $permission = in_array($kind, ['quote_submitted', 'quote_withdrawn'], true)
            ? 'purchasing.rfq.manage'
            : 'purchasing.rfq.view';
        $audience = User::query()
            ->whereHas('role.permissions', fn ($query) => $query->where('slug', $permission))
            ->where('is_active', true)
            ->get();
        if ($rfq->purchaseRequest?->requested_by) {
            $requester = User::query()->whereKey($rfq->purchaseRequest->requested_by)->where('is_active', true)->first();
            if ($requester) {
                $audience->push($requester);
            }
        }
        if ($audience->isEmpty()) {
            return;
        }

        [$type, $title, $message] = $this->internalCopy($rfq, $kind);
        $this->notifications->send($audience->unique('id'), $type, [
            'title' => $title,
            'message' => $message,
            'link_to' => '/purchasing/rfqs/'.$rfq->hash_id,
            'entity_type' => 'request_for_quote',
            'entity_id' => $rfq->hash_id,
        ]);
    }

    private function deliverToSuppliers(RequestForQuote $rfq, string $kind): void
    {
        foreach ($rfq->invitations as $invitation) {
            $invitation->forceFill(['portal_notified_at' => now(), 'last_notification_error' => null])->save();
            $email = $invitation->vendor?->email;
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invitation->forceFill(['last_notification_error' => 'Supplier has no usable email address.'])->save();

                continue;
            }
            try {
                Mail::to($email)->queue(new SupplierRfqLifecycleMail($rfq, $invitation, $kind, app(EmailDeliveryFailureNotifier::class)->userIdsWithPermission('purchasing.rfq.view')));
                $invitation->forceFill(['email_notified_at' => now()])->save();
            } catch (\Throwable $e) {
                $invitation->forceFill(['last_notification_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
                Log::warning('RFQ supplier email could not be queued', ['rfq_id' => $rfq->id, 'invitation_id' => $invitation->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @return array{0:string,1:string,2:string} */
    private function internalCopy(RequestForQuote $rfq, string $kind): array
    {
        $number = $rfq->rfq_number;

        return match ($kind) {
            'published' => ['purchasing.rfq_published', "RFQ {$number} published", 'Invited suppliers can now submit sealed quotations.'],
            'extended' => ['purchasing.rfq_extended', "RFQ {$number} extended", 'The supplier submission deadline changed.'],
            'addendum' => ['purchasing.rfq_addendum', "RFQ {$number} addendum published", 'Review the clarification before evaluating quotations.'],
            'closed' => ['purchasing.rfq_closed', "RFQ {$number} closed", 'Supplier prices are now available to authorized evaluators.'],
            'awarded' => ['purchasing.rfq_awarded', "RFQ {$number} awarded", 'Draft purchase orders were generated from the sourcing decision.'],
            'no_award' => ['purchasing.rfq_no_award', "RFQ {$number} has no award", 'The purchase request is available for a new sourcing decision.'],
            'cancelled' => ['purchasing.rfq_cancelled', "RFQ {$number} cancelled", 'The sourcing event will not accept further supplier activity.'],
            'quote_submitted' => ['purchasing.rfq_quote_submitted', "RFQ {$number} quote submitted", 'A supplier submitted a quotation for evaluation after closure.'],
            'quote_withdrawn' => ['purchasing.rfq_quote_withdrawn', "RFQ {$number} quote withdrawn", 'A supplier withdrew its current quotation while the event was open.'],
            'quote_reconfirmation_required' => ['purchasing.rfq_quote_reconfirmation', "RFQ {$number} quote reconfirmation required", 'A winning quotation expired before PO approval.'],
            default => ['purchasing.rfq_updated', "RFQ {$number} updated", 'Review the sourcing event for the latest status.'],
        };
    }
}
