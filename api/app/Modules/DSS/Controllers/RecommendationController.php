<?php

declare(strict_types=1);

namespace App\Modules\DSS\Controllers;

use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Requests\ListRecommendationsRequest;
use App\Modules\DSS\Requests\RecommendationActionRequest;
use App\Modules\DSS\Requests\RecordOutcomeRequest;
use App\Modules\DSS\Resources\RecommendationResource;
use App\Modules\DSS\Services\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecommendationController
{
    public function __construct(
        private RecommendationService $service,
    ) {}

    public function index(ListRecommendationsRequest $request): AnonymousResourceCollection
    {
        $page = $this->service->list($request->user(), $request->validated());

        return RecommendationResource::collection($page);
    }

    public function summary(ListRecommendationsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->service->summary($request->user(), $request->validated())]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->service->options(request()->user())]);
    }

    public function show(DssRecommendation $recommendation): RecommendationResource
    {
        return new RecommendationResource(
            $this->service->show(request()->user(), $recommendation)
        );
    }

    public function act(RecommendationActionRequest $request, DssRecommendation $recommendation): RecommendationResource
    {
        $validated = $request->validated();

        return new RecommendationResource(
            $this->service->act($request->user(), $recommendation, $validated['action'], $validated)
        );
    }

    public function outcome(RecordOutcomeRequest $request, DssRecommendation $recommendation): RecommendationResource
    {
        return new RecommendationResource(
            $this->service->recordOutcome($request->user(), $recommendation, $request->validated()['outcome'])
        );
    }
}
