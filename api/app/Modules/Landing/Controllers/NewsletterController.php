<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Landing\Requests\SubscribeNewsletterRequest;
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
}
