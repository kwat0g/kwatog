<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\EmailBrandingService;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Illuminate\Queue\SerializesModels;

class InterviewDetailsUpdatedMail extends QueuedMailable
{
    use SerializesModels;

    public readonly string $applicantName;
    public readonly string $positionTitle;
    public readonly string $scheduledAt;
    public readonly string $location;
    public readonly string $interviewerName;
    public readonly ?string $outcome;
    public readonly string $trackingCode;
    private readonly string $applicationHashId;

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        private readonly JobApplication $application,
        private readonly ApplicationInterview $interview,
        public readonly array $fallbackUserIds = [],
    ) {
        // Lane: mail — see config/horizon.php. Kept off `default` so an
        // email burst cannot sit in front of an approval or a GRN notice.
        $this->onQueue('mail');
        $this->afterCommit();
        $this->snapshot = $snapshot = self::snapshotForEmail($application, $interview);
        $this->applicantName = (string) $snapshot['applicant_name'];
        $this->positionTitle = (string) $snapshot['position_title'];
        $this->scheduledAt = $snapshot['scheduled_at']
            ? Carbon::parse($snapshot['scheduled_at'])->timezone(config('app.timezone'))->format('F j, Y g:i A')
            : 'To be confirmed';
        $this->location = (string) $snapshot['location'];
        $this->interviewerName = (string) $snapshot['interviewer_name'];
        $this->outcome = null;
        $this->trackingCode = (string) $snapshot['tracking_code'];
        $this->applicationHashId = (string) $snapshot['application_hash_id'];
    }

    public function envelope(): Envelope
    {
        $snapshot = $this->snapshotData();
        $brand = app(EmailBrandingService::class)->data();

        return new Envelope(subject: $this->safeHeaderText("[{$brand['name']}] Interview details updated: {$snapshot['position_title']}"));
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();
        $snapshot = $this->snapshotData();
        $scheduledAt = $snapshot['scheduled_at']
            ? Carbon::parse($snapshot['scheduled_at'])->timezone(config('app.timezone'))->format('F j, Y g:i A').' (Asia/Manila)'
            : 'To be confirmed';

        return new Content(
            markdown: 'emails.recruitment.interview-updated',
            with: [
                'title' => 'Interview details updated',
                'preheader' => 'Updated interview details for '.$snapshot['position_title'].'.',
                'applicantName' => $this->safeMarkdownText($snapshot['applicant_name'], 'Applicant'),
                'positionTitle' => $this->safeMarkdownText($snapshot['position_title'], 'the position'),
                'scheduledAt' => $scheduledAt,
                'location' => $this->safeMarkdownText($snapshot['location'], 'To be confirmed'),
                'interviewerName' => $this->safeMarkdownText($snapshot['interviewer_name'], 'To be confirmed'),
                'outcome' => null,
                'trackingCode' => $this->safeMarkdownText($snapshot['tracking_code'], 'Not available'),
                'trackingUrl' => $snapshot['tracking_url'],
                'companyName' => $this->safeMarkdownText($brand['name'], 'the company'),
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        $snapshot = $this->snapshotData();
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Recruitment interview update',
            "The interview update email for {$snapshot['applicant_name']} could not be delivered. Review the interview and contact the candidate through an approved channel.",
            [
                'link_to' => '/hr/recruitment/applications/'.$snapshot['application_hash_id'],
                'entity_type' => 'job_application',
                'entity_id' => $snapshot['application_hash_id'],
                'reason' => 'The candidate interview update email job failed in the queue.',
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

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Interview details updated');
    }

    private function safeMarkdownText(?string $value, string $fallback): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? $fallback : (preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? $fallback);
    }
}
