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
        $now = now();

        NewsletterSubscriber::query()->upsert(
            [[
                'email' => $email,
                'status' => NewsletterStatus::Subscribed->value,
                'ip_address' => $request->ip(),
                'consent_at' => $now,
                'unsubscribed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['email'],
            ['status', 'ip_address', 'consent_at', 'unsubscribed_at', 'updated_at'],
        );
    }

    /**
     * Opt a subscriber out by email address. Idempotent, and a no-op for an
     * unknown address so the public endpoint cannot be used to enumerate who is
     * subscribed.
     */
    public function unsubscribeByEmail(string $email): void
    {
        NewsletterSubscriber::query()
            ->where('email', $email)
            ->update([
                'status'          => NewsletterStatus::Unsubscribed->value,
                'unsubscribed_at' => now(),
                'updated_at'      => now(),
            ]);
    }

    /** Opt a subscriber out from a signed unsubscribe link. Idempotent. */
    public function unsubscribe(NewsletterSubscriber $subscriber): void
    {
        if ($subscriber->status === NewsletterStatus::Unsubscribed) {
            return;
        }

        $subscriber->forceFill([
            'status'          => NewsletterStatus::Unsubscribed->value,
            'unsubscribed_at' => now(),
        ])->save();
    }
}
