<?php

declare(strict_types=1);

namespace App\Modules\Landing\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Landing\Notifications\ContactInquiryReceivedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ContactInquiryService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param array{full_name: string, company?: string|null, email: string, phone?: string|null, message: string} $data
     */
    public function create(array $data, Request $request): ContactInquiry
    {
        $inquiry = DB::transaction(function () use ($data, $request): ContactInquiry {
            $inquiry = new ContactInquiry();
            $inquiry->fill([
                ...$data,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
            $inquiry->inquiry_no = $this->sequences->generate('contact_inquiry');
            $inquiry->status = ContactInquiryStatus::New;
            $inquiry->save();

            return $inquiry;
        });

        // Outside the transaction: a mail failure must not lose the inquiry.
        // The row is the record of truth; the notification is a convenience.
        try {
            Notification::route('mail', $this->settings->requiredString('company.sales_inbox_email'))
                ->notify(new ContactInquiryReceivedNotification($inquiry));
        } catch (\Throwable $e) {
            $recipients = User::query()
                ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'crm.inquiries.view'))
                ->where('is_active', true)
                ->get();

            app(EmailDeliveryFailureNotifier::class)->notify(
                $recipients,
                'Contact inquiry',
                "The sales inbox email for inquiry {$inquiry->inquiry_no} could not be delivered. Review the inquiry in the CRM.",
                [
                    'link_to' => "/crm/inquiries/{$inquiry->hash_id}",
                    'entity_type' => 'contact_inquiry',
                    'entity_id' => $inquiry->hash_id,
                    'reason' => 'The configured sales inbox was unreachable or the email provider rejected the message.',
                ],
            );
            Log::warning('Contact inquiry notification failed', [
                'inquiry_no' => $inquiry->inquiry_no,
                'error' => $e->getMessage(),
            ]);
        }

        return $inquiry;
    }
}
