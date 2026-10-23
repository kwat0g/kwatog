<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\EmailBrandingService;
use App\Modules\HR\Enums\ApplicationStage;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationStatusUpdatedMail extends QueuedMailable
{
    use SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly string $applicantName,
        public readonly string $positionTitle,
        public readonly string $previousStage,
        public readonly string $currentStage,
        public readonly string $trackingCode,
        public readonly string $applicationHashId,
        public readonly array $fallbackUserIds = [],
    ) {
        // Lane: mail — see config/horizon.php. Kept off `default` so an
        // email burst cannot sit in front of an approval or a GRN notice.
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data();

        return new Envelope(subject: $this->safeHeaderText("[{$brand['name']}] Application update: {$this->positionTitle}"));
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.recruitment.application-status-updated',
            with: [
                'title' => 'Application status update',
                'preheader' => 'Your application status for '.$this->positionTitle.' has changed.',
                'applicantNameForEmail' => $this->safeMarkdownText($this->applicantName, 'Applicant'),
                'positionTitleForEmail' => $this->safeMarkdownText($this->positionTitle, 'the position'),
                'previousStageLabel' => $this->label($this->previousStage),
                'currentStageLabel' => $this->label($this->currentStage),
                'trackingCodeForEmail' => $this->safeMarkdownText($this->trackingCode, 'Not available'),
                'trackingUrlForEmail' => $base.'/careers/track',
                'companyNameForEmail' => $this->safeMarkdownText($brand['name'], 'the company'),
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Recruitment application update',
            "The application update email for {$this->applicantName} could not be delivered. Review the application and contact the candidate through an approved channel.",
            [
                'link_to' => '/hr/recruitment/applications/'.$this->applicationHashId,
                'entity_type' => 'job_application',
                'reason' => 'The candidate application update email job failed in the queue.',
            ],
        );
    }

    private function label(string $stage): string
    {
        return ApplicationStage::tryFrom($stage)?->publicLabel() ?? 'Application updated';
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Application update');
    }

    private function safeMarkdownText(?string $value, string $fallback): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? $fallback : (preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? $fallback);
    }
}
