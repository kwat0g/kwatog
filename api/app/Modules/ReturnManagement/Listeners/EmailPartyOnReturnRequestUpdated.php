<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Events\ReturnRequestUpdated;
use App\Modules\ReturnManagement\Mail\ReturnRequestUpdateMail;
use App\Modules\ReturnManagement\Support\ReturnManagementAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailPartyOnReturnRequestUpdated implements ShouldQueue
{
    public int $tries = 3;

    public function handle(ReturnRequestUpdated $event): void
    {
        $rma = $event->returnRequest;
        $snapshot = $event->emailSnapshot;
        if ($snapshot === []) {
            $rma->loadMissing(['customer', 'vendor']);
            $party = $rma->type === ReturnRequestType::CustomerReturn ? $rma->customer : $rma->vendor;
            $snapshot = [
                'rma_id' => (int) $rma->getKey(),
                'hash_id' => (string) $rma->hash_id,
                'rma_number' => (string) $rma->rma_number,
                'type' => $rma->type?->value,
                'status_label' => $rma->status?->label() ?? (string) $rma->status,
                'action' => $event->action,
                'party_email' => $party?->email,
            ];
        }

        $permission = 'return_management.view';
        $fallback = app(EmailDeliveryFailureNotifier::class);
        $fallbackUserIds = $fallback->userIdsWithPermission($permission);
        $isCustomer = ($snapshot['type'] ?? null) === ReturnRequestType::CustomerReturn->value;
        if (! $isCustomer && $fallbackUserIds !== []) {
            $fallbackUserIds = User::query()->with('role')->whereIn('id', $fallbackUserIds)->get()
                ->reject(fn (User $user): bool => ReturnManagementAccess::customerReturnsOnly($user))
                ->modelKeys();
        }
        $context = [
            'link_to' => '/return-management/'.$rma->hash_id,
            'entity_type' => 'return_request',
            'entity_id' => $rma->hash_id,
            'reason' => 'The party email was missing, invalid, unreachable, or rejected by the email provider.',
        ];
        $partyLabel = $isCustomer ? 'customer' : 'supplier';
        $partyEmail = trim((string) ($snapshot['party_email'] ?? ''));

        if (! filter_var($partyEmail, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                $permission,
                'Return request email',
                "Return request {$snapshot['rma_number']} changed to {$snapshot['status_label']} but the {$partyLabel} has no usable email address. Contact the {$partyLabel} through an approved channel.",
                $context,
            );
            return;
        }

        try {
            $deliveryId = ($event->notificationId ?? '') !== ''
                ? hash('sha256', 'return-request-update|'.$event->notificationId.'|'.mb_strtolower($partyEmail))
                : null;
            Mail::to($partyEmail)->queue(new ReturnRequestUpdateMail(
                $rma,
                $event->action,
                $fallback->userIdsWithPermission($permission),
                $snapshot,
                $deliveryId,
            ));
        } catch (\Throwable $e) {
            $fallback->notifyUserIds(
                $fallbackUserIds,
                'Return request email',
                "The update email for return request {$snapshot['rma_number']} could not be accepted by the email provider. Contact the {$partyLabel} through an approved channel.",
                $context,
            );
            Log::warning('Return request email enqueue failed', [
                'return_request_id' => $snapshot['rma_id'] ?? $rma->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

}
