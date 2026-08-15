<?php

declare(strict_types=1);

namespace App\Common\Mail;

use App\Common\Enums\ExportFormat;
use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ScheduledExportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $exportName,
        public readonly string $module,
        public readonly string $filename,
        public readonly string $bytes,
        public readonly ExportFormat $format,
        public readonly ?int $ownerId = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('mail.from.name', 'Ogami Philippines').'] Scheduled export: '.$this->exportName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails/scheduled-export',
            with: [
                'exportName' => $this->exportName,
                'module' => $this->module,
                'filename' => $this->filename,
                'format' => strtoupper($this->format->value),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->bytes, $this->filename)
                ->withMime($this->format->mimeType()),
        ];
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserId(
            $this->ownerId,
            'Scheduled export',
            "The scheduled export '{$this->exportName}' could not be delivered. Review the export configuration and run it again.",
            [
                'link_to' => '/admin/scheduled-exports',
                'entity_type' => 'scheduled_export',
                'reason' => 'The email provider rejected or could not deliver the export.',
            ],
        );
    }
}
