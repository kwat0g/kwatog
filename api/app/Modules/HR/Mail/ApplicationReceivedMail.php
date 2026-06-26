<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationReceivedMail extends Mailable
{
    public function __construct(
        public readonly JobApplication $application,
        public readonly JobPosting $posting,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Application Received — {$this->posting->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recruitment.application-received',
            with: [
                'applicantName' => $this->application->full_name,
                'positionTitle' => $this->posting->title,
                'trackingCode'  => $this->application->tracking_code,
                'trackingUrl'   => config('app.frontend_url') . '/careers/track',
            ],
        );
    }
}
