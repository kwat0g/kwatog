<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Landing\Models\NewsletterSubscriber;
use App\Modules\Landing\Requests\SubscribeNewsletterRequest;
use App\Modules\Landing\Requests\UnsubscribeNewsletterRequest;
use App\Modules\Landing\Services\NewsletterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Render a confirmation page. The signed GET must not mutate state because
     * link scanners and browser prefetchers can request it automatically.
     */
    public function unsubscribeViaLink(Request $request, NewsletterSubscriber $subscriber): \Symfony\Component\HttpFoundation\Response
    {
        $action = htmlspecialchars($request->fullUrl(), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($subscriber->email, ENT_QUOTES, 'UTF-8');

        return response()->make(
            '<!doctype html><html><head><meta charset="utf-8"><title>Unsubscribe</title></head>'
            .'<body><h1>Unsubscribe from Ogami updates</h1>'
            .'<p>Confirm unsubscribing '. $email .'.</p>'
            .'<form method="post" action="'. $action .'">'
            .'<button type="submit">Unsubscribe</button></form></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public function confirmUnsubscribeViaLink(NewsletterSubscriber $subscriber): JsonResponse
    {
        $this->service->unsubscribe($subscriber);

        return response()->json(['message' => 'You have been unsubscribed.']);
    }
}
