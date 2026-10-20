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
use Illuminate\Support\Carbon;
use Illuminate\Queue\SerializesModels;

class InterviewScheduledMail extends QueuedMailable
{
    use SerializesModels;

    /** @var array<string, mixed> */
    public array $snapshot = [];

    public function __construct(
        public readonly JobApplication $application,
        public readonly ApplicationInterview $interview,
        /** @var list<int> */
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
        $this->snapshot = self::snapshotForEmail($application, $interview);
    }

    public function envelope(): Envelope
    {
        $snapshot = $this->snapshotData();

        return new Envelope(
            subject: "Interview Scheduled — {$snapshot['position_title']}",
        );
    }

    public function content(): Content
    {
        $settings = app(SettingsService::class);
        $brand = app(EmailBrandingService::class)->data();
        $snapshot = $this->snapshotData();

        return new Content(
            markdown: 'emails.recruitment.interview-scheduled',
            with: [
                'applicantName'   => $snapshot['applicant_name'],
                'positionTitle'   => $snapshot['position_title'],
                'scheduledAt'     => Carbon::parse($snapshot['scheduled_at'])->format('F j, Y g:i A'),
                'location'        => $snapshot['location'],
                'interviewerName' => $snapshot['interviewer_name'],
                'companyName' => $brand['name'],
                'companyAddress' => $settings->requiredString('company.address'),
                'trackingCode' => $snapshot['tracking_code'],
                'trackingUrl' => $snapshot['tracking_url'],
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

    /** @return array<string, mixed> */
    private static function snapshotForEmail(JobApplication $application, ApplicationInterview $interview): array
    {
        $application->loadMissing('jobPosting');

        return [
            'applicant_name' => $application->full_name,
            'position_title' => (string) $application->jobPosting->title,
            'scheduled_at' => $interview->scheduled_at?->toIso8601String(),
            'location' => $interview->location,
            'interviewer_name' => $interview->interviewer_name,
            'tracking_code' => (string) $application->tracking_code,
            'application_hash_id' => (string) $application->hash_id,
            'tracking_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/careers/track',
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotData(): array
    {
        return $this->snapshot ?: self::snapshotForEmail($this->application, $this->interview);
    }
}
