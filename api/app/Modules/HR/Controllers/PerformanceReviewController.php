<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Modules\HR\Enums\ReviewCycleType;
use App\Modules\HR\Enums\ReviewCycleStatus;
use App\Modules\HR\Enums\ReviewStatus;
use App\Modules\HR\Enums\PerformanceRatingCategory;
use App\Modules\HR\Enums\PerformanceOverallRating;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use App\Modules\HR\Resources\PerformanceReviewResource;
use App\Modules\HR\Resources\ReviewCycleResource;
use App\Modules\HR\Resources\ReviewTemplateResource;
use App\Modules\HR\Services\PerformanceReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class PerformanceReviewController extends Controller
{
    public function __construct(private readonly PerformanceReviewService $service, private readonly SettingsService $settings) {}

    public function cycles(Request $request): AnonymousResourceCollection
    {
        return ReviewCycleResource::collection($this->service->listCycles($request->all()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'review_statuses' => array_map(
                static fn (ReviewStatus $status): array => ['value' => $status->value, 'label' => str_replace('_', ' ', ucfirst($status->value))],
                ReviewStatus::cases(),
            ),
            'statuses' => array_map(
                static fn (ReviewCycleStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)],
                ReviewCycleStatus::cases(),
            ),
            'cycle_types' => array_map(
                static fn (ReviewCycleType $type): array => [
                    'value' => $type->value,
                    'label' => str_replace('_', ' ', ucfirst($type->value)),
                ],
                ReviewCycleType::cases(),
            ),
            'rating_scale' => array_values(array_filter((array) $this->settings->get('hr.performance.rating_scale', []), static fn ($score): bool => is_array($score) && isset($score['value'], $score['label']))),
            'rating_categories' => array_map(
                static fn (PerformanceRatingCategory $category): array => ['value' => $category->value, 'label' => $category->label()],
                PerformanceRatingCategory::cases(),
            ),
            'overall_ratings' => array_map(
                static fn (PerformanceOverallRating $rating): array => ['value' => $rating->value, 'label' => $rating->label()],
                PerformanceOverallRating::cases(),
            ),
        ]]);
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'cycle_type'  => ['required', Rule::enum(ReviewCycleType::class)],
            'start_date'  => ['required', 'date'],
            'end_date'    => ['required', 'date', 'after:start_date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return (new ReviewCycleResource($this->service->createCycle($data)))
            ->response()
            ->setStatusCode(201);
    }

    public function activateCycle(ReviewCycle $cycle): JsonResponse
    {
        return response()->json(['data' => new ReviewCycleResource($this->service->activateCycle($cycle))]);
    }

    public function closeCycle(ReviewCycle $cycle): JsonResponse
    {
        return response()->json(['data' => new ReviewCycleResource($this->service->closeCycle($cycle))]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->all();

        // The SPA sends hashed ids as `cycle_id`; the service filters on the raw
        // `review_cycle_id` column. Decoding here keeps a hash string from ever
        // reaching a bigint comparison (Postgres 22P02).
        $filters['review_cycle_id'] = HashIdFilter::decode($filters['cycle_id'] ?? $filters['review_cycle_id'] ?? null, ReviewCycle::class);
        $filters['employee_id']     = HashIdFilter::decode($filters['employee_id'] ?? null, Employee::class);

        if (! $user->can('hr.performance.manage')) {
            $filters['scoped_employee_id'] = $user->employee?->id;
        }

        return PerformanceReviewResource::collection($this->service->listReviews($filters))->response();
    }

    public function show(PerformanceReview $review, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('hr.performance.manage')) {
            $empId = $user->employee?->id;
            abort_unless(
                $empId && ($review->employee_id === $empId || $review->reviewer_id === $empId),
                403,
                'Access denied.'
            );
        }

        $review->loadMissing(['employee:id,first_name,last_name', 'reviewer:id,first_name,last_name', 'cycle:id,name']);

        return response()->json(['data' => new PerformanceReviewResource($review)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'review_cycle_id' => ['required', 'string'],
            'employee_id'     => ['required', 'string'],
            'reviewer_id'     => ['required', 'string'],
            'template_id'     => ['nullable', 'string'],
        ]);

        $hashids = app('hashids');
        foreach (['review_cycle_id', 'employee_id', 'reviewer_id', 'template_id'] as $field) {
            if (!empty($data[$field])) {
                $decoded = $hashids->decode($data[$field]);
                abort_if(empty($decoded), 422, "Invalid {$field}.");
                $data[$field] = $decoded[0];
            }
        }

        return response()->json(['data' => new PerformanceReviewResource($this->withRefs($this->service->createReview($data)))], 201);
    }

    public function submit(PerformanceReview $review, Request $request): JsonResponse
    {
        $employeeId = $request->user()->employee?->id;
        abort_unless($employeeId && $employeeId === $review->reviewer_id, 403, 'Only the assigned reviewer may submit.');

        // Accept the stable API slug used by older clients while persisting the
        // canonical enum value used by the HR domain.
        if (is_string($request->input('overall_rating'))) {
            $slug = strtolower(str_replace([' ', '-'], '_', $request->input('overall_rating')));
            $canonical = collect(PerformanceOverallRating::cases())
                ->first(fn (PerformanceOverallRating $rating): bool => strtolower(str_replace([' ', '-'], '_', $rating->value)) === $slug)
                ?->value;
            if ($canonical !== null) {
                $request->merge(['overall_rating' => $canonical]);
            }
        }

        $data = $request->validate([
            'ratings'        => ['required', 'array'],
            'ratings.*'      => ['required', 'numeric', 'min:1', 'max:5'],
            'strengths'      => ['nullable', 'string', 'max:5000'],
            'improvements'   => ['nullable', 'string', 'max:5000'],
            'goals'          => ['nullable', 'string', 'max:5000'],
            'overall_score'  => ['required', 'decimal:0,2', 'min:1', 'max:5'],
            'overall_rating' => ['required', Rule::in(PerformanceOverallRating::values())],
        ]);

        return response()->json(['data' => new PerformanceReviewResource($this->withRefs($this->service->submitReview($review, $data)))]);
    }

    public function acknowledge(PerformanceReview $review, Request $request): JsonResponse
    {
        $employeeId = $request->user()->employee?->id;
        abort_unless($employeeId && $employeeId === $review->employee_id, 403, 'Only the reviewed employee may acknowledge.');

        return response()->json(['data' => new PerformanceReviewResource($this->withRefs($this->service->acknowledge($review)))]);
    }

    /**
     * The service returns `$review->fresh()` from the write paths, which drops
     * eager loads. Strict mode forbids lazy loading, so the resource would
     * throw on `$this->employee` — load the refs it needs up front.
     */
    private function withRefs(PerformanceReview $review): PerformanceReview
    {
        $review->loadMissing([
            'employee:id,first_name,last_name',
            'reviewer:id,first_name,last_name',
            'cycle:id,name',
        ]);

        return $review;
    }

    public function templates(): JsonResponse
    {
        return ReviewTemplateResource::collection($this->service->listTemplates())->response();
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'criteria'    => ['required', 'array'],
        ]);

        return response()->json(['data' => new ReviewTemplateResource($this->service->createTemplate($data))], 201);
    }
}
