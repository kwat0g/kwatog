<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Requests\AdvanceApplicationRequest;
use App\Modules\HR\Requests\StoreInterviewRequest;
use App\Modules\HR\Resources\ApplicationInterviewResource;
use App\Modules\HR\Resources\JobApplicationResource;
use App\Modules\HR\Resources\RecruitmentApplicationEventResource;
use App\Modules\HR\Services\RecruitmentService;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\InterviewOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class RecruitmentApplicationController
{
    public function __construct(private RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'stage' => ['nullable', Rule::enum(ApplicationStage::class)],
            'job_posting_id' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in([
                'application_number',
                'full_name',
                'stage',
                'applied_at',
                'created_at',
            ])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = JobApplication::with(['jobPosting:id,title,posting_number']);

        if (! empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }
        if (! empty($filters['job_posting_id'])) {
            $postingId = JobPosting::tryDecodeHash((string) $filters['job_posting_id']);
            abort_if($postingId === null, 422, 'Invalid job posting.');
            $query->where('job_posting_id', $postingId);
        }
        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(fn ($q) => $q
                ->where('application_number', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"));
        }

        $sort = $filters['sort'] ?? 'applied_at';
        $direction = $filters['direction'] ?? 'desc';
        if ($sort === 'full_name') {
            $query->orderBy('last_name', $direction)->orderBy('first_name', $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        return JobApplicationResource::collection(
            $query->paginate((int) ($filters['per_page'] ?? 25))
        );
    }

    public function show(JobApplication $jobApplication): JobApplicationResource
    {
        return new JobApplicationResource(
            $jobApplication->load([
                'jobPosting.department',
                'interviews.createdBy',
                'notes.user',
                'events.actor',
                'convertedEmployee',
            ])
        );
    }

    public function changeStage(AdvanceApplicationRequest $request, JobApplication $jobApplication): JobApplicationResource
    {
        if ($request->input('action') === 'reject') {
            $this->service->rejectApplication(
                $jobApplication,
                $request->input('rejection_reason'),
                $request->user(),
            );
        } else {
            $interviewData = $request->input('interview');
            if ($interviewData) {
                $interviewData['created_by'] = $request->user()->id;
            }
            $this->service->advanceStage($jobApplication, $interviewData, $request->user());
        }

        return new JobApplicationResource($jobApplication->fresh()->load('jobPosting'));
    }

    public function storeInterview(StoreInterviewRequest $request, JobApplication $jobApplication): ApplicationInterviewResource
    {
        $interview = $this->service->scheduleInterview($jobApplication, $request->validated() + [
            'created_by' => $request->user()->id,
        ], $request->user());

        return new ApplicationInterviewResource($interview);
    }

    public function updateInterview(Request $request, ApplicationInterview $interview): ApplicationInterviewResource
    {
        $data = $request->validate([
            'scheduled_at'     => ['sometimes', 'date', 'after:now'],
            'location'         => ['sometimes', 'nullable', 'string', 'max:200'],
            'interviewer_name' => ['sometimes', 'string', 'max:200'],
            'notes'            => ['sometimes', 'nullable', 'string'],
            'outcome'          => ['sometimes', 'nullable', Rule::enum(InterviewOutcome::class)],
        ]);

        $this->service->updateInterview($interview, $data, $request->user());
        return new ApplicationInterviewResource($interview->fresh());
    }

    public function history(JobApplication $jobApplication): AnonymousResourceCollection
    {
        return RecruitmentApplicationEventResource::collection(
            $jobApplication->events()->with('actor')->get()
        );
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
