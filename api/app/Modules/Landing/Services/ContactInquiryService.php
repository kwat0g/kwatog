<?php

declare(strict_types=1);

namespace App\Modules\Landing\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\SettingsService;
use App\Modules\Landing\Enums\ContactInquiryStatus;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Landing\Mail\ContactInquiryReceivedMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactInquiryService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array{full_name: string, company?: string|null, email: string, phone?: string|null, message: string, consent?: bool|null}  $data
     */
    public function create(array $data, Request $request): ContactInquiry
    {
        // `consent` is a request-only flag; the persisted evidence is consent_at.
        unset($data['consent']);

        $inquiry = DB::transaction(function () use ($data, $request): ContactInquiry {
            $inquiry = new ContactInquiry;
            $inquiry->fill([
                ...$data,
                'ip_address' => $request->ip(),
                // contact_inquiries.user_agent is varchar(255); keep public
                // submissions inside the database contract.
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);
            $inquiry->inquiry_no = $this->sequences->generate('contact_inquiry');
            $inquiry->status = ContactInquiryStatus::New;
            // Acceptance of the privacy notice — server clock, never the client's.
            $inquiry->consent_at = now();
            $inquiry->save();

            return $inquiry;
        });

        // Outside the transaction: a mail failure must not lose the inquiry.
        // The row is the record of truth; the notification is a convenience.
        try {
            $recipient = trim($this->settings->requiredString('company.sales_inbox_email'));
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Configured sales inbox address is invalid.');
            }

            Mail::to($recipient)->queue(new ContactInquiryReceivedMail(
                $inquiry->inquiry_no,
                (string) $inquiry->hash_id,
                $inquiry->created_at->copy()->timezone((string) config('app.timezone'))->format('j M Y, g:i A T'),
                hash('sha256', 'contact-inquiry|'.$inquiry->getKey()),
            ));
        } catch (\Throwable $e) {
            app(EmailDeliveryFailureNotifier::class)->notifyPermission(
                'crm.inquiries.view',
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
                'error_class' => $e::class,
            ]);
        }

        return $inquiry;
    }
}
