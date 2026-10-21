<?php

declare(strict_types=1);

namespace App\Common\Mail;

use App\Common\Enums\ExportFormat;
use App\Common\Models\ScheduledExport;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\Export\ExportRunner;
use App\Modules\Auth\Models\User;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduledExportMail extends QueuedMailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $exportName,
        public readonly string $module,
        public readonly string $filename,
        string $bytes,
        public readonly ExportFormat $format,
        public readonly ?int $ownerId = null,
        bool $artifactPath = false,
        public readonly ?int $scheduleId = null,
        public readonly ?string $authorizationContext = null,
        public readonly ?string $generatedAt = null,
        ?string $deliveryKey = null,
    ) {
        // Queue payloads are JSON encoded by the Redis connector. XLSX/CSV
        // output is arbitrary binary data, so carrying it directly in a
        // queued mailable causes "Malformed UTF-8" failures before the job
        // reaches the worker. Base64 keeps the payload transport-safe while
        // preserving the original bytes for the attachment.
        $this->artifactPath = $artifactPath ? $bytes : null;
        $this->encodedBytes = $artifactPath ? null : base64_encode($bytes);
        $this->deliveryKey = $deliveryKey;
        $this->afterCommit();
    }

    private readonly ?string $encodedBytes;

    private readonly ?string $artifactPath;

    /** Runs from SendQueuedMailable before any attachment reaches the transport. */
    public function send($mailer)
    {
        // Ownerless explicit system mail remains supported; user mail must
        // never become a system path when its owner or schedule disappears.
        if ($this->ownerId !== null || isset($this->scheduleId)) {
            $schedule = ! isset($this->scheduleId) ? null : ScheduledExport::query()->find($this->scheduleId);
            try {
                if (! $schedule || ! $schedule->is_active
                    || (int) $schedule->owner_id !== $this->ownerId
                    || $schedule->module !== $this->module || ! isset($this->authorizationContext)) {
                    throw ValidationException::withMessages(['owner' => 'Scheduled export authorization is no longer valid.']);
                }
                $owner = User::query()->find($this->ownerId);
                if (! $owner) {
                    throw ValidationException::withMessages(['owner' => 'Export owner no longer exists.']);
                }
                $ownerEmail = trim((string) $owner->email);
                $queuedRecipients = array_map(
                    static fn (mixed $recipient): string => is_array($recipient)
                        ? trim((string) ($recipient['address'] ?? ''))
                        : trim((string) $recipient),
                    $this->to,
                );
                if (! $owner->is_active || filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false
                    || count($queuedRecipients) !== 1
                    || strtolower($queuedRecipients[0]) !== strtolower($ownerEmail)) {
                    throw ValidationException::withMessages(['recipients' => 'Scheduled exports can only be sent to the active schedule owner.']);
                }
                $runner = app(ExportRunner::class);
                $runner->build($schedule->module, (array) $schedule->columns, (array) ($schedule->filters ?? []), $owner);
                if (! hash_equals($this->authorizationContext, $runner->authorizationContext($owner, (array) $schedule->columns, (array) ($schedule->filters ?? [])))) {
                    throw ValidationException::withMessages(['owner' => 'Export authorization or row scope changed; review and run a new export.']);
                }
            } catch (ValidationException $exception) {
                if ($schedule) {
                    $schedule->update(['is_active' => false, 'last_error' => $exception->getMessage()]);
                }
                if ($this->artifactPath !== null) {
                    Storage::disk('local')->delete($this->artifactPath);
                }

                return null;
            }
        }

        return parent::send($mailer);
    }

    public function envelope(): Envelope
    {
        $brandName = app(EmailBrandingService::class)->data()['name'];
        $safeName = Str::squish(str_replace(["\r", "\n"], ' ', $this->exportName));

        return new Envelope(
            subject: '['.$brandName.'] Scheduled export ready: '.$safeName,
        );
    }

    public function content(): Content
    {
        $formatLabel = $this->format === ExportFormat::Csv ? 'CSV file' : 'Excel workbook';
        $moduleLabel = Str::headline(str_replace('.', ' ', $this->module));
        $moduleLabel = preg_replace('/\bHr\b/', 'HR', $moduleLabel) ?? $moduleLabel;

        return new Content(
            markdown: 'emails/scheduled-export',
            with: [
                'exportName' => $this->exportName,
                'moduleLabel' => $moduleLabel,
                'filename' => $this->filename,
                'formatLabel' => $formatLabel,
                'generatedAt' => $this->generatedAt,
                'preheader' => 'Your scheduled report is ready. The selected export file is attached.',
                'title' => 'Your scheduled export is ready',
                'scheduleUrl' => rtrim(config('app.frontend_url', config('app.url')), '/').'/admin/scheduled-exports',
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
