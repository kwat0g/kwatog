<?php

declare(strict_types=1);

namespace App\Common\Mail;

use App\Common\Services\EmailDeliveryLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Throwable;

abstract class QueuedMailable extends Mailable implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    protected ?string $deliveryKey = null;

    public function queue(Queue $queue)
    {
        $this->applyMailQueueDefaults();

        try {
            return parent::queue($queue);
        } catch (Throwable $exception) {
            app(EmailDeliveryLogService::class)->markQueueFailed($this->deliveryKey, $exception);

            throw $exception;
        }
    }

    public function later($delay, Queue $queue)
    {
        $this->applyMailQueueDefaults();

        try {
            return parent::later($delay, $queue);
        } catch (Throwable $exception) {
            app(EmailDeliveryLogService::class)->markQueueFailed($this->deliveryKey, $exception);

            throw $exception;
        }
    }

    public function build(): static
    {
        if ($this->deliveryKey !== null) {
            $deliveryKey = $this->deliveryKey;
            $this->withSymfonyMessage(static function (Email $message) use ($deliveryKey): void {
                $message->getHeaders()->addTextHeader('X-OGAMI-DELIVERY-ID', $deliveryKey);
            });
        }

        return $this;
    }

    protected function applyMailQueueDefaults(): void
    {
        $this->queue ??= 'mail';
        $this->afterCommit ??= true;
        $this->deliveryKey ??= (string) Str::uuid();

        app(EmailDeliveryLogService::class)->queueMailable($this, $this->deliveryKey, Auth::id());
    }
}
