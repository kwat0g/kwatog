<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Mail\QueuedMailable;
use App\Common\Models\EmailDelivery;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Str;
use Throwable;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailDeliveryLogService
{
    private ?string $queueJobId = null;

    private ?string $activeDeliveryKey = null;

    public function queueMailable(QueuedMailable $mailable, string $deliveryKey, ?int $triggeredBy): void
    {
        EmailDelivery::query()->firstOrCreate(
            ['delivery_key' => $deliveryKey],
            [
                'email_type' => $mailable::class,
                'recipients' => $this->mailableRecipients($mailable),
                'subject' => $mailable->envelope()->subject,
                'status' => 'queued',
                'queued_at' => now(),
                'triggered_by' => $triggeredBy,
            ],
        );
    }

    public function markQueueFailed(string $deliveryKey, Throwable $exception): void
    {
        EmailDelivery::query()->where('delivery_key', $deliveryKey)->update([
            'status' => 'failed',
            'error_code' => $exception::class,
            'failed_at' => now(),
        ]);
    }

    public function beginJob(JobProcessing $event): void
    {
        $this->queueJobId = $event->job->uuid() ?: (string) $event->job->getJobId();
        $this->activeDeliveryKey = null;
    }

    public function beginMessage(MessageSending $event): ?bool
    {
        $message = $event->message;
        $keyHeader = $message->getHeaders()->get('X-OGAMI-DELIVERY-ID');
        $deliveryKey = $keyHeader?->getBodyAsString();
        $recipients = $this->messageRecipients($message);
        $subject = $message->getSubject();

        if (! is_string($deliveryKey) || $deliveryKey === '') {
            $deliveryKey = $this->queueJobId === null
                ? (string) Str::uuid()
                : hash('sha256', $this->queueJobId.'|'.json_encode($recipients).'|'.$subject);
        }

        $row = EmailDelivery::query()->firstOrCreate(
            ['delivery_key' => $deliveryKey],
            [
                'email_type' => $event->data['__laravel_mailable'] ?? 'notification',
                'recipients' => $recipients,
                'subject' => $subject,
                'status' => 'sending',
                'attempts' => 1,
                'queued_at' => $this->queueJobId === null ? null : now(),
            ],
        );

        if (! $row->wasRecentlyCreated) {
            if (in_array($row->status, ['sent', 'unknown'], true)) {
                $this->activeDeliveryKey = null;

                return false;
            }

            if ($row->status === 'sending') {
                $row->update([
                    'status' => 'unknown',
                    'error_code' => 'ambiguous_previous_attempt',
                    'failed_at' => now(),
                ]);
                $this->activeDeliveryKey = null;

                return false;
            }

            $row->update([
                'status' => 'sending',
                'attempts' => $row->attempts + 1,
                'error_code' => null,
                'failed_at' => null,
            ]);
        }

        $this->activeDeliveryKey = $deliveryKey;

        return null;
    }

    public function markSent(MessageSent $event): void
    {
        if ($this->activeDeliveryKey === null) {
            return;
        }

        EmailDelivery::query()->where('delivery_key', $this->activeDeliveryKey)->update([
            'status' => 'sent',
            'provider_message_id' => $event->sent->getSymfonySentMessage()->getMessageId(),
            'sent_at' => now(),
            'error_code' => null,
        ]);

        $this->activeDeliveryKey = null;
    }

    public function markFailed(JobExceptionOccurred $event): void
    {
        if ($this->activeDeliveryKey !== null) {
            EmailDelivery::query()->where('delivery_key', $this->activeDeliveryKey)->update([
                'status' => 'failed',
                'error_code' => $event->exception::class,
                'failed_at' => now(),
            ]);
        }

        $this->activeDeliveryKey = null;
    }

    public function endJob(JobProcessed|JobExceptionOccurred $event): void
    {
        $this->activeDeliveryKey = null;
        $this->queueJobId = null;
    }

    private function mailableRecipients(QueuedMailable $mailable): array
    {
        return [
            'to' => $this->addressList($mailable->to),
            'cc' => $this->addressList($mailable->cc),
            'bcc' => $this->addressList($mailable->bcc),
        ];
    }

    private function messageRecipients(Email $message): array
    {
        return [
            'to' => $this->addressList($message->getTo() ?? []),
            'cc' => $this->addressList($message->getCc() ?? []),
            'bcc' => $this->addressList($message->getBcc() ?? []),
        ];
    }

    /** @param iterable<array{address?: string}|Address> $addresses
     *  @return list<string>
     */
    private function addressList(iterable $addresses): array
    {
        $list = [];

        foreach ($addresses as $address) {
            $value = $address instanceof Address ? $address->getAddress() : ($address['address'] ?? null);
            if (is_string($value) && $value !== '') {
                $list[] = strtolower($value);
            }
        }

        $list = array_values(array_unique($list));
        sort($list);

        return $list;
    }
}
