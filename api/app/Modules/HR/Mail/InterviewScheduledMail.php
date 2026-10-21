<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\SettingsService;
use App\Common\Services\EmailBrandingService;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InterviewScheduledMail extends QueuedMailable
{
    public readonly string $applicantName;
    public readonly string $positionTitle;
    public readonly string $scheduledAt;
    public readonly string $location;
    public readonly string $interviewerName;
    public readonly string $companyAddress;
    public readonly string $trackingCode;
    private readonly string $applicationHashId;

    public function __construct(
        JobApplication $application,
        ApplicationInterview $interview,
        /** @var list<int> */
        public readonly array $fallbackUserIds = [],
    ) {
        $this->applicantName = $application->full_name;
        $this->positionTitle = $application->jobPosting->title;
        $this->scheduledAt = $interview->scheduled_at->copy()->setTimezone((string) config('app.timezone', 'Asia/Manila'))->format('F j, Y g:i A');
        $this->location = $interview->location ?? 'To be confirmed';
        $this->interviewerName = $interview->interviewer_name;
        $this->companyAddress = app(SettingsService::class)->requiredString('company.address');
        $this->trackingCode = $application->tracking_code;
        $this->applicationHashId = $application->hash_id;
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Interview Scheduled — {$this->positionTitle}",
        );
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.recruitment.interview-scheduled',
            with: [
                'applicantName'   => $this->applicantName,
                'positionTitle'   => $this->positionTitle,
                'scheduledAt'     => $this->scheduledAt,
                'location'        => $this->location,
                'interviewerName' => $this->interviewerName,
                'companyName' => $brand['name'],
                'companyAddress' => $this->companyAddress,
                'trackingCode' => $this->trackingCode,
                'trackingUrl' => $base.'/careers/track',
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Recruitment interview notification',
            "The interview email for {$this->applicantName} could not be delivered. Review the interview and contact the candidate through an approved channel.",
            [
                'link_to' => '/hr/recruitment/applications/'.$this->applicationHashId,
                'entity_type' => 'job_application',
                'entity_id' => $this->applicationHashId,
                'reason' => 'The candidate interview email job failed in the queue.',
            ],
        );
    }
}
