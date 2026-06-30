<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Requests\StoreJobPostingRequest;
use App\Modules\HR\Requests\UpdateJobPostingRequest;
use App\Modules\HR\Resources\JobPostingResource;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecruitmentPostingController
{
    public function __construct(private RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JobPosting::with(['department', 'position'])
            ->withCount('applications')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return JobPostingResource::collection($query->paginate(15));
    }

    public function store(StoreJobPostingRequest $request): JobPostingResource
    {
        $posting = $this->service->createPosting(
            $request->validated() + ['created_by' => $request->user()->id]
        );

        return new JobPostingResource($posting->load(['department', 'position']));
    }

    public function show(JobPosting $jobPosting): JobPostingResource
    {
        return new JobPostingResource(
            $jobPosting->load(['department', 'position', 'createdBy'])
                ->loadCount('applications')
        );
    }

    public function update(UpdateJobPostingRequest $request, JobPosting $jobPosting): JobPostingResource
    {
        $posting = $this->service->updatePosting($jobPosting, $request->validated());
        return new JobPostingResource($posting->load(['department', 'position']));
    }

    public function destroy(JobPosting $jobPosting): JsonResponse
    {
        if ($jobPosting->status !== JobPostingStatus::Draft) {
            abort(422, 'Only draft postings can be deleted.');
        }

        if ($jobPosting->applications()->exists()) {
            abort(422, 'Cannot delete a posting that has applications.');
        }

        $jobPosting->delete();
        return response()->json(null, 204);
    }

    public function changeStatus(Request $request, JobPosting $jobPosting): JobPostingResource
    {
        $request->validate(['status' => ['required', 'in:open,closed,filled']]);
        $this->service->changePostingStatus($jobPosting, JobPostingStatus::from($request->input('status')));
        return new JobPostingResource($jobPosting->fresh()->load(['department', 'position']));
    }
}
