<?php

declare(strict_types=1);

namespace App\Modules\DSS\Controllers;

use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Requests\ListRecommendationsRequest;
use App\Modules\DSS\Requests\RecommendationActionRequest;
use App\Modules\DSS\Requests\RecordOutcomeRequest;
use App\Modules\DSS\Resources\RecommendationEventResource;
use App\Modules\DSS\Resources\RecommendationResource;
use App\Modules\DSS\Services\RecommendationService;
use App\Modules\DSS\Support\MasterHygieneScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function events(DssRecommendation $recommendation): JsonResponse
    {
        $page = $this->service->events(request()->user(), $recommendation);

        return response()->json([
            'data' => RecommendationEventResource::collection($page->getCollection())->resolve(request()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function affectedRecords(Request $request, DssRecommendation $recommendation, MasterHygieneScope $scope): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        $recommendation = $this->service->show($user, $recommendation);
        abort_unless($recommendation->detector_id === 'dss.master_hygiene', 404);

        $context = $recommendation->verification_context;
        $gap = is_array($context) ? ($context['gap'] ?? null) : null;
        $rationale = $recommendation->rationale;
        $gap ??= is_array($rationale) ? ($rationale['gap'] ?? null) : null;
        abort_unless(is_string($gap) && isset($scope->definitions()[$gap]), 404);
        abort_unless($user->hasPermission($scope->definitions()[$gap]['permission']), 403);

        if (! in_array($recommendation->status, ['open', 'in_progress', 'snoozed'], true)) {
            return response()->json(['data' => [], 'total' => 0, 'next_cursor' => null, 'historical' => true]);
        }

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $query = $scope->query($gap);
        if (is_string($filters['search'] ?? null) && trim($filters['search']) !== '') {
            $scope->applySearch($query, $gap, $filters['search']);
        }

        $total = (clone $query)->count();
        $page = $query->orderBy('id')->cursorPaginate(
            (int) ($filters['per_page'] ?? 25), ['*'], 'cursor', $filters['cursor'] ?? null,
        );

        return response()->json([
            'data' => $page->getCollection()->map(fn ($record) => $scope->present($gap, $record))->values(),
            'total' => $total,
            'next_cursor' => $page->nextCursor()?->encode(),
            'historical' => false,
        ]);
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
