<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use App\Modules\HR\Resources\ReviewCycleResource;
use App\Modules\HR\Services\PerformanceReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class PerformanceReviewController extends Controller
{
    public function __construct(private readonly PerformanceReviewService $service) {}

    public function cycles(Request $request): AnonymousResourceCollection
    {
        return ReviewCycleResource::collection($this->service->listCycles($request->all()));
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'cycle_type'  => ['required', 'string'],
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

        if (! $user->can('hr.performance.manage')) {
            $filters['scoped_employee_id'] = $user->employee?->id;
        }

        return response()->json($this->service->listReviews($filters));
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

        return response()->json(['data' => $this->service->createReview($data)], 201);
    }

    public function submit(PerformanceReview $review, Request $request): JsonResponse
    {
        $employeeId = $request->user()->employee?->id;
        abort_unless($employeeId && $employeeId === $review->reviewer_id, 403, 'Only the assigned reviewer may submit.');

        $data = $request->validate([
            'ratings'        => ['required', 'array'],
            'strengths'      => ['nullable', 'string', 'max:5000'],
            'improvements'   => ['nullable', 'string', 'max:5000'],
            'goals'          => ['nullable', 'string', 'max:5000'],
            'overall_score'  => ['required', 'decimal:0,2', 'min:1', 'max:5'],
            'overall_rating' => ['required', 'string', 'max:30'],
        ]);

        return response()->json(['data' => $this->service->submitReview($review, $data)]);
    }

    public function acknowledge(PerformanceReview $review, Request $request): JsonResponse
    {
        $employeeId = $request->user()->employee?->id;
        abort_unless($employeeId && $employeeId === $review->employee_id, 403, 'Only the reviewed employee may acknowledge.');

        return response()->json(['data' => $this->service->acknowledge($review)]);
    }

    public function templates(): JsonResponse
    {
        return response()->json($this->service->listTemplates());
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'criteria'    => ['required', 'array'],
        ]);

        return response()->json(['data' => $this->service->createTemplate($data)], 201);
    }
}
