<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\EmailBrandingService;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationReceivedMail extends QueuedMailable
{
    use SerializesModels;

    /** @var array<string, string> */
    public array $snapshot = [];

    public function __construct(
        public readonly JobApplication $application,
        public readonly JobPosting $posting,
        /** @var list<int> */
        public readonly array $fallbackUserIds = [],
    ) {
        // Lane: mail — see config/horizon.php. Kept off `default` so an
        // email burst cannot sit in front of an approval or a GRN notice.
        $this->onQueue('mail');
        $this->afterCommit();
        $this->snapshot = self::snapshotForEmail($application, $posting);
    }

    public function envelope(): Envelope
    {
        $snapshot = $this->snapshotData();

        return new Envelope(
            subject: "Application Received — {$snapshot['position_title']}",
        );
    }

    public function content(): Content
    {
        $snapshot = $this->snapshotData();
        $brand = app(EmailBrandingService::class)->data();

        return new Content(
            markdown: 'emails.recruitment.application-received',
            with: [
                'applicantName' => $snapshot['applicant_name'],
                'positionTitle' => $snapshot['position_title'],
                'trackingCode'  => $snapshot['tracking_code'],
                'trackingUrl'   => $snapshot['tracking_url'],
                'companyName' => $brand['name'],
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Recruitment application confirmation',
            "The application confirmation email for {$this->application->full_name} could not be delivered. Review the application and contact the candidate through an approved channel.",
            [
                'link_to' => '/hr/recruitment/applications/'.$this->application->hash_id,
                'entity_type' => 'job_application',
                'entity_id' => $this->application->hash_id,
                'reason' => 'The candidate email job failed in the queue.',
            ],
        );
    }

    /** @return array<string, string> */
    private static function snapshotForEmail(JobApplication $application, JobPosting $posting): array
    {
        return [
            'applicant_name' => $application->full_name,
            'position_title' => (string) $posting->title,
            'tracking_code' => (string) $application->tracking_code,
            'application_hash_id' => (string) $application->hash_id,
            'tracking_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/careers/track',
        ];
    }

    /** @return array<string, string> */
    private function snapshotData(): array
    {
        return $this->snapshot ?: self::snapshotForEmail($this->application, $this->posting);
    }
}
