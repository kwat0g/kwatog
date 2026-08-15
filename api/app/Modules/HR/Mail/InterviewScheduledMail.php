<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\SettingsService;
use App\Common\Services\EmailBrandingService;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InterviewScheduledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly JobApplication $application,
        public readonly ApplicationInterview $interview,
        /** @var list<int> */
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Interview Scheduled — {$this->application->jobPosting->title}",
        );
    }

    public function content(): Content
    {
        $settings = app(SettingsService::class);
        $brand = app(EmailBrandingService::class)->data();
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.recruitment.interview-scheduled',
            with: [
                'applicantName'   => $this->application->full_name,
                'positionTitle'   => $this->application->jobPosting->title,
                'scheduledAt'     => $this->interview->scheduled_at->format('F j, Y g:i A'),
                'location'        => $this->interview->location ?? 'To be confirmed',
                'interviewerName' => $this->interview->interviewer_name,
                'companyName' => $brand['name'],
                'companyAddress' => $settings->requiredString('company.address'),
                'trackingCode' => $this->application->tracking_code,
                'trackingUrl' => $base.'/careers/track',
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Recruitment interview notification',
            "The interview email for {$this->application->full_name} could not be delivered. Review the interview and contact the candidate through an approved channel.",
            [
                'link_to' => '/hr/recruitment/applications/'.$this->application->hash_id,
                'entity_type' => 'job_application',
                'entity_id' => $this->application->hash_id,
                'reason' => 'The candidate interview email job failed in the queue.',
            ],
        );
    }
}
