<?php

declare(strict_types=1);

namespace App\Modules\Landing\Services;

use App\Modules\Landing\Enums\NewsletterStatus;
use App\Modules\Landing\Models\NewsletterSubscriber;
use Illuminate\Http\Request;

class NewsletterService
{
    public function subscribe(string $email, Request $request): void
    {
        $subscriber = NewsletterSubscriber::updateOrCreate(
            ['email' => $email],
            ['ip_address' => $request->ip(), 'unsubscribed_at' => null],
        );

        if ($subscriber->status === NewsletterStatus::Unsubscribed) {
            $subscriber->forceFill(['status' => NewsletterStatus::Subscribed->value])->save();
        }
    }
}
