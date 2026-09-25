<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReturnCaseUpdateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reference,
        public readonly string $statusLabel,
        public readonly string $realm,
        public readonly string $caseHash,
        public readonly array $fallbackUserIds,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reference.' — report updated');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.returns.case-update', with: [
            'portalUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/'.$this->realm.'/problems/'.$this->caseHash,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds($this->fallbackUserIds,
            'Return case', $this->reference.' email could not be delivered. Contact the customer or supplier to follow up.',
            ['link_to' => '/return-management/cases/'.$this->caseHash, 'entity_type' => 'return_case', 'entity_id' => $this->caseHash]);
    }
}
