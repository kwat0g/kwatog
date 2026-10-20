<?php

declare(strict_types=1);

namespace App\Common\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

abstract class QueuedMailable extends Mailable implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function queue(Queue $queue)
    {
        $this->applyMailQueueDefaults();

        return parent::queue($queue);
    }

    public function later($delay, Queue $queue)
    {
        $this->applyMailQueueDefaults();

        return parent::later($delay, $queue);
    }

    protected function applyMailQueueDefaults(): void
    {
        $this->queue ??= 'mail';
        $this->afterCommit ??= true;
    }
}
