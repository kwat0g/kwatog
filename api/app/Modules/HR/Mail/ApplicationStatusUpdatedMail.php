<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\EmailBrandingService;
use App\Modules\HR\Enums\ApplicationStage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationStatusUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

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
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Application update — {$this->positionTitle}");
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.recruitment.application-status-updated',
            with: [
                'applicantName' => $this->applicantName,
                'positionTitle' => $this->positionTitle,
                'previousStage' => $this->label($this->previousStage),
                'currentStage' => $this->label($this->currentStage),
                'trackingCode' => $this->trackingCode,
                'trackingUrl' => $base.'/careers/track',
                'companyName' => $brand['name'],
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
        return ApplicationStage::tryFrom($stage)?->publicLabel() ?? ucfirst($stage);
    }
}
