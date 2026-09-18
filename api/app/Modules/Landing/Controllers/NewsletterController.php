<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Landing\Models\NewsletterSubscriber;
use App\Modules\Landing\Requests\SubscribeNewsletterRequest;
use App\Modules\Landing\Requests\UnsubscribeNewsletterRequest;
use App\Modules\Landing\Services\NewsletterService;
use Illuminate\Http\JsonResponse;

class NewsletterController
{
    public function __construct(
        private readonly NewsletterService $service,
        private readonly SettingsService $settings,
    ) {}

    public function store(SubscribeNewsletterRequest $request): JsonResponse
    {
        $this->service->subscribe($request->validated('email'), $request);

        $company = trim((string) $this->settings->get('company.legal_name', ''));
        $message = 'You are subscribed.';
        if ($company !== '') {
            $message .= " Thanks for your interest in {$company}.";
        }

        return response()->json(['message' => $message]);
    }

    /**
     * Public opt-out by email. Returns the same message whether or not the
     * address was subscribed, so it cannot be used to enumerate subscribers.
     */
    public function unsubscribe(UnsubscribeNewsletterRequest $request): JsonResponse
    {
        $this->service->unsubscribeByEmail($request->validated('email'));

        return response()->json(['message' => 'You have been unsubscribed.']);
    }

    /**
     * One-click opt-out from a signed link (no login, no form). The `signed`
     * middleware rejects tampered or expired URLs before the controller runs.
     */
    public function unsubscribeViaLink(NewsletterSubscriber $subscriber): JsonResponse
    {
        $this->service->unsubscribe($subscriber);

        return response()->json(['message' => 'You have been unsubscribed.']);
    }
}
