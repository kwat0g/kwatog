<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Events\ReturnRequestUpdated;
use App\Modules\ReturnManagement\Mail\ReturnRequestUpdateMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailPartyOnReturnRequestUpdated implements ShouldQueue
{
    public int $tries = 3;

    public function handle(ReturnRequestUpdated $event): void
    {
        $rma = $event->returnRequest->loadMissing(['customer', 'vendor', 'items.product', 'invoice', 'purchaseOrder']);
        $isCustomer = $rma->type === ReturnRequestType::CustomerReturn;
        $party = $isCustomer ? $rma->customer : $rma->vendor;
        $permission = 'return_management.view';
        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/return-management/return-requests/'.$rma->hash_id,
            'entity_type' => 'return_request',
            'entity_id' => $rma->hash_id,
            'reason' => 'The party email was missing, invalid, unreachable, or rejected by the email provider.',
        ];
        $partyLabel = $isCustomer ? 'customer' : 'supplier';

        if (! filter_var($party?->email, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                $permission,
                'Return request email',
                "Return request {$rma->rma_number} changed to {$this->statusLabel($rma)} but the {$partyLabel} has no usable email address. Contact the {$partyLabel} through an approved channel.",
                $context,
            );
            return;
        }

        try {
            Mail::to($party->email)->queue(new ReturnRequestUpdateMail(
                $rma,
                $event->action,
                $fallback->userIdsWithPermission($permission),
            ));
        } catch (\Throwable $e) {
            $fallback->notifyPermission(
                $permission,
                'Return request email',
                "The update email for return request {$rma->rma_number} could not be accepted by the email provider. Contact the {$partyLabel} through an approved channel.",
                $context,
            );
            Log::warning('Return request email enqueue failed', [
                'return_request_id' => $rma->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function statusLabel($rma): string
    {
        return $rma->status?->label() ?? (string) $rma->status;
    }
}
