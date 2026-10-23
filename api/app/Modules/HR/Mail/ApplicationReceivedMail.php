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
        $brand = app(EmailBrandingService::class)->data();

        return new Envelope(
            subject: $this->safeHeaderText("[{$brand['name']}] Application received: {$snapshot['position_title']}"),
        );
    }

    public function content(): Content
    {
        $snapshot = $this->snapshotData();
        $brand = app(EmailBrandingService::class)->data();

        return new Content(
            markdown: 'emails.recruitment.application-received',
            with: [
                'title' => 'Application received',
                'preheader' => 'We received your application for '.$snapshot['position_title'].'.',
                'applicantName' => $this->safeMarkdownText($snapshot['applicant_name'], 'Applicant'),
                'positionTitle' => $this->safeMarkdownText($snapshot['position_title'], 'the position'),
                'trackingCode'  => $this->safeMarkdownText($snapshot['tracking_code'], 'Not available'),
                'trackingUrl'   => $snapshot['tracking_url'],
                'companyName' => $this->safeMarkdownText($brand['name'], 'the company'),
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

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Application received');
    }

    private function safeMarkdownText(?string $value, string $fallback): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? $fallback : (preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? $fallback);
    }
}
