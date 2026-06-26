<?php

declare(strict_types=1);

namespace App\Modules\HR\Mail;

use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InterviewScheduledMail extends Mailable
{
    public function __construct(
        public readonly JobApplication $application,
        public readonly ApplicationInterview $interview,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Interview Scheduled — {$this->application->jobPosting->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recruitment.interview-scheduled',
            with: [
                'applicantName'   => $this->application->full_name,
                'positionTitle'   => $this->application->jobPosting->title,
                'scheduledAt'     => $this->interview->scheduled_at->format('F j, Y g:i A'),
                'location'        => $this->interview->location ?? 'To be confirmed',
                'interviewerName' => $this->interview->interviewer_name,
            ],
        );
    }
}
