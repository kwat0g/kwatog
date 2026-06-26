<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Requests\PublicApplicationRequest;
use App\Modules\HR\Resources\PublicJobPostingResource;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicRecruitmentController extends Controller
{
    public function __construct(private RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JobPosting::with('department')
            ->where('status', JobPostingStatus::Open)
            ->orderByDesc('posted_at');

        if ($request->filled('department_id')) {
            $decoded = app('hashids')->decode($request->input('department_id'));
            if (!empty($decoded)) {
                $query->where('department_id', $decoded[0]);
            }
        }

        return PublicJobPostingResource::collection($query->paginate(12));
    }

    public function show(JobPosting $jobPosting): PublicJobPostingResource
    {
        if ($jobPosting->status !== JobPostingStatus::Open) {
            abort(404);
        }

        return new PublicJobPostingResource($jobPosting->load('department'));
    }

    public function apply(PublicApplicationRequest $request, JobPosting $jobPosting): JsonResponse
    {
        if ($jobPosting->status !== JobPostingStatus::Open) {
            abort(422, 'This position is no longer accepting applications.');
        }

        if ($jobPosting->closes_at && $jobPosting->closes_at->isPast()) {
            abort(422, 'The application deadline has passed.');
        }

        $application = $this->service->submitApplication(
            $jobPosting,
            $request->validated(),
            $request->file('resume'),
        );

        return response()->json([
            'tracking_code' => $application->tracking_code,
            'message'       => 'Application submitted successfully.',
        ], 201);
    }

    public function track(string $trackingCode): JsonResponse
    {
        $info = $this->service->getTrackingInfo($trackingCode);

        if (!$info) {
            abort(404, 'Application not found.');
        }

        return response()->json(['data' => $info]);
    }
}
