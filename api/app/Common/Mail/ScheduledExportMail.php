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
        string $bytes,
        public readonly ExportFormat $format,
        public readonly ?int $ownerId = null,
        bool $artifactPath = false,
    ) {
        // Queue payloads are JSON encoded by the Redis connector. XLSX/CSV
        // output is arbitrary binary data, so carrying it directly in a
        // queued mailable causes "Malformed UTF-8" failures before the job
        // reaches the worker. Base64 keeps the payload transport-safe while
        // preserving the original bytes for the attachment.
        $this->artifactPath = $artifactPath ? $bytes : null;
        $this->encodedBytes = $artifactPath ? null : base64_encode($bytes);
        $this->afterCommit();
    }

    private readonly ?string $encodedBytes;
    private readonly ?string $artifactPath;

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
        if ($this->artifactPath !== null) {
            return [
                Attachment::fromStorageDisk('local', $this->artifactPath)
                    ->as($this->filename)
                    ->withMime($this->format->mimeType()),
            ];
        }

        return [
            Attachment::fromData(function (): string {
                $bytes = base64_decode((string) $this->encodedBytes, true);
                if ($bytes === false) {
                    throw new \RuntimeException('Scheduled export attachment payload is invalid.');
                }

                return $bytes;
            }, $this->filename)
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
