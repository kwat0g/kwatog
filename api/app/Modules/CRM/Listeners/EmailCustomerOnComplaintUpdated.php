<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\CRM\Events\CustomerComplaintUpdated;
use App\Modules\CRM\Mail\CustomerComplaintUpdateMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailCustomerOnComplaintUpdated implements ShouldQueue
{
    public int $tries = 3;

    public function handle(CustomerComplaintUpdated $event): void
    {
        $complaint = $event->complaint->loadMissing(['customer', 'product', 'salesOrder', 'eightDReport']);
        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/crm/complaints/'.$complaint->hash_id,
            'entity_type' => 'customer_complaint',
            'entity_id' => $complaint->hash_id,
            'reason' => 'The customer email was missing, invalid, unreachable, or rejected by the email provider.',
        ];

        if (! filter_var($complaint->customer?->email, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                'crm.complaints.manage',
                'Customer complaint email',
                "Complaint {$complaint->complaint_number} changed to {$this->statusLabel($complaint)} but the customer has no usable email address. Contact the customer through an approved channel.",
                $context,
            );
            return;
        }

        try {
            Mail::to($complaint->customer->email)->queue(new CustomerComplaintUpdateMail(
                $complaint,
                $event->action,
                $fallback->userIdsWithPermission('crm.complaints.manage'),
            ));
        } catch (\Throwable $e) {
            $fallback->notifyPermission(
                'crm.complaints.manage',
                'Customer complaint email',
                "The update email for complaint {$complaint->complaint_number} could not be accepted by the email provider. Contact the customer through an approved channel.",
                $context,
            );
            Log::warning('Customer complaint email enqueue failed', [
                'complaint_id' => $complaint->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function statusLabel($complaint): string
    {
        return $complaint->status?->label() ?? (string) $complaint->status;
    }
}
