<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Requests\AdvanceApplicationRequest;
use App\Modules\HR\Requests\StoreInterviewRequest;
use App\Modules\HR\Resources\ApplicationInterviewResource;
use App\Modules\HR\Resources\JobApplicationResource;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class RecruitmentApplicationController
{
    public function __construct(private RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JobApplication::with(['jobPosting:id,title,posting_number'])
            ->orderByDesc('applied_at');

        if ($request->filled('stage')) {
            $query->where('stage', $request->input('stage'));
        }
        if ($request->filled('job_posting_id')) {
            $decoded = app('hashids')->decode($request->input('job_posting_id'));
            if (!empty($decoded)) {
                $query->where('job_posting_id', $decoded[0]);
            }
        }

        return JobApplicationResource::collection($query->paginate(20));
    }

    public function show(JobApplication $jobApplication): JobApplicationResource
    {
        return new JobApplicationResource(
            $jobApplication->load(['jobPosting.department', 'interviews.createdBy', 'notes.user', 'convertedEmployee'])
        );
    }

    public function changeStage(AdvanceApplicationRequest $request, JobApplication $jobApplication): JobApplicationResource
    {
        if ($request->input('action') === 'reject') {
            $this->service->rejectApplication($jobApplication, $request->input('rejection_reason'));
        } else {
            $interviewData = $request->input('interview');
            if ($interviewData) {
                $interviewData['created_by'] = $request->user()->id;
            }
            $this->service->advanceStage($jobApplication, $interviewData);
        }

        return new JobApplicationResource($jobApplication->fresh()->load('jobPosting'));
    }

    public function storeInterview(StoreInterviewRequest $request, JobApplication $jobApplication): ApplicationInterviewResource
    {
        $interview = $this->service->scheduleInterview($jobApplication, $request->validated() + [
            'created_by' => $request->user()->id,
        ]);

        return new ApplicationInterviewResource($interview);
    }

    public function updateInterview(Request $request, ApplicationInterview $interview): ApplicationInterviewResource
    {
        $data = $request->validate([
            'notes'   => ['nullable', 'string'],
            'outcome' => ['nullable', 'in:pending,passed,failed'],
        ]);

        $this->service->updateInterview($interview, $data);
        return new ApplicationInterviewResource($interview->fresh());
    }

    public function storeNote(Request $request, JobApplication $jobApplication): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $note = $this->service->addNote($jobApplication, $data['body'], $request->user());

        return response()->json([
            'data' => [
                'id'   => $note->hash_id,
                'body' => $note->body,
                'user' => ['id' => $request->user()->hash_id, 'name' => $request->user()->name],
                'created_at' => $note->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function downloadResume(JobApplication $jobApplication): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!Storage::disk('local')->exists($jobApplication->resume_path)) {
            abort(404, 'Resume file not found.');
        }

        return Storage::disk('local')->download(
            $jobApplication->resume_path,
            $jobApplication->resume_original_name
        );
    }

    public function conversionData(JobApplication $jobApplication): JsonResponse
    {
        if ($jobApplication->stage->value !== 'hired') {
            abort(422, 'Only hired applicants can be converted.');
        }

        return response()->json(['data' => $this->service->getConversionData($jobApplication)]);
    }
}
