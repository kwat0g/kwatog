<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Modules\Landing\Requests\SubscribeNewsletterRequest;
use App\Modules\Landing\Services\NewsletterService;
use Illuminate\Http\JsonResponse;

class NewsletterController
{
    public function __construct(private readonly NewsletterService $service) {}

    public function store(SubscribeNewsletterRequest $request): JsonResponse
    {
        $this->service->subscribe($request->validated('email'), $request);

        return response()->json(['message' => 'You are subscribed. Thanks for your interest in Ogami.']);
    }
}
