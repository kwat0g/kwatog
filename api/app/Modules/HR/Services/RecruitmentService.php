<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\InterviewOutcome;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Mail\ApplicationReceivedMail;
use App\Modules\HR\Mail\ApplicationStatusUpdatedMail;
use App\Modules\HR\Mail\InterviewDetailsUpdatedMail;
use App\Modules\HR\Mail\InterviewScheduledMail;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\ApplicationNote;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\RecruitmentApplicationEvent;
use App\Modules\HR\Support\RecruitmentApplicationStateMachine;
use App\Modules\HR\Support\RecruitmentNotificationRecipients;
use App\Modules\HR\Support\RecruitmentPostingStateMachine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RecruitmentService
{
    private const DUPLICATE_EMAIL_INDEX = 'job_applications_posting_email_unique';

    public function __construct(
        private DocumentSequenceService $sequences,
        private NotificationService $notifications,
        private SettingsService $settings,
    ) {}

    public function createPosting(array $data): JobPosting
    {
        return DB::transaction(function () use ($data) {
            $data['posting_number'] = $this->sequences->generate('job_posting');
            $posting = new JobPosting;
            $posting->fill($data);
            $posting->status = JobPostingStatus::Draft;
            $posting->save();

            return $posting;
        });
    }

    public function updatePosting(JobPosting $posting, array $data): JobPosting
    {
        return DB::transaction(function () use ($posting, $data): JobPosting {
            $locked = JobPosting::query()->lockForUpdate()->findOrFail($posting->id);
            $locked->update($data);

            return $locked->fresh();
        });
    }

    public function changePostingStatus(
        JobPosting $posting,
        JobPostingStatus $newStatus,
    ): void {
        DB::transaction(function () use ($posting, $newStatus): void {
            // The bound model may be stale by the time an HR action arrives.
            // The transition decision must be made from the row locked in this
            // transaction, not from a request-time snapshot.
            $locked = JobPosting::withTrashed()->lockForUpdate()->findOrFail($posting->id);

            RecruitmentPostingStateMachine::assertCanTransition($locked->status, $newStatus);

            if ($newStatus === JobPostingStatus::Open && ! $locked->posted_at) {
                $locked->posted_at = now();
            }
            $locked->status = $newStatus;
            $locked->save();
        });
    }

    public function restorePosting(JobPosting $posting): void
    {
        DB::transaction(function () use ($posting): void {
            $locked = JobPosting::withTrashed()->lockForUpdate()->findOrFail($posting->id);
            if (! $locked->trashed()) {
                throw new BusinessRuleException('Job posting is already active.');
            }

            $locked->restore();
        });
    }

    public function archivePosting(JobPosting $posting): void
    {
        DB::transaction(function () use ($posting): void {
            // Locking the parent row serializes this check with inserts that
            // satisfy the job_applications foreign key on PostgreSQL.
            $locked = JobPosting::query()->lockForUpdate()->findOrFail($posting->id);

            if ($locked->status !== JobPostingStatus::Draft) {
                throw new BusinessRuleException('Only draft postings can be deleted.');
            }

            if ($locked->applications()->exists()) {
                throw new BusinessRuleException('Cannot delete a posting that has applications.');
            }

            $locked->delete();
        });
    }

    public function submitApplication(JobPosting $posting, array $data, UploadedFile $resume): JobApplication
    {
        $path = $resume->store('recruitment/resumes/'.now()->format('Y/m'), 'local');
        if ($path === false) {
            // Storage fault, not a rejected application. Left unmapped so the
            // applicant is not told to fix a file that was fine.
            throw new \RuntimeException('Unable to store the uploaded resume.');
        }

        try {
            $application = DB::transaction(function () use ($posting, $data, $resume, $path): JobApplication {
                // The controller's read is only an early user-facing guard.
                // Re-lock and re-check the authoritative posting here so a
                // close/archive racing this write cannot accept a late CV.
                $lockedPosting = JobPosting::query()
                    ->lockForUpdate()
                    ->findOrFail($posting->id);

                if ($lockedPosting->status !== JobPostingStatus::Open) {
                    throw new BusinessRuleException('This position is no longer accepting applications.');
                }

                if ($lockedPosting->closes_at && $lockedPosting->closes_at->isPast()) {
                    throw new BusinessRuleException('The application deadline has passed.');
                }

                $application = new JobApplication;
                $application->fill([
                    'application_number' => $this->sequences->generate('job_application'),
                    'job_posting_id' => $lockedPosting->id,
                    'tracking_code' => $this->generateTrackingCode(),
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    // Keep direct service callers consistent with the public
                    // request: email uniqueness is case-insensitive and does
                    // not depend on accidental surrounding whitespace.
                    'email' => strtolower(trim((string) $data['email'])),
                    'phone' => $data['phone'],
                    'resume_path' => $path,
                    'resume_original_name' => $resume->getClientOriginalName(),
                    'cover_letter' => $data['cover_letter'] ?? null,
                    'applied_at' => now(),
                ]);
                $application->stage = ApplicationStage::New;
                $application->save();

                return $application;
            });
        } catch (QueryException $e) {
            Storage::disk('local')->delete($path);

            if ($this->isDuplicateApplicationEmailViolation($e)) {
                throw ValidationException::withMessages([
                    'email' => 'You have already applied for this position.',
                ]);
            }

            throw $e;
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        $posting = $application->jobPosting()->withTrashed()->firstOrFail();

        try {
            Mail::to($application->email)->queue(
                new ApplicationReceivedMail($application, $posting, $this->hrUserIds())
            );
        } catch (\Throwable $e) {
            Log::warning('Application saved but its confirmation notification failed.', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            app(EmailDeliveryFailureNotifier::class)->notify(
                $this->hrUsers(),
                'Recruitment application confirmation',
                "The confirmation email for {$application->full_name} could not be delivered. Review the application and contact the candidate through an approved channel.",
                [
                    'link_to' => "/hr/recruitment/applications/{$application->hash_id}",
                    'entity_type' => 'job_application',
                    'entity_id' => $application->hash_id,
                    'reason' => 'The candidate email address was unreachable or the email provider rejected the message.',
                ],
            );
        }

        try {
            // This internal alert is independent from the candidate email.
            // HR must still see every saved application when the mail provider is down.
            $this->notifyHrNewApplication($application, $posting);
        } catch (\Throwable $e) {
            Log::warning('Application saved but HR inbox notification failed.', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $application;
    }

    public function advanceStage(
        JobApplication $application,
        ?array $interviewData = null,
        ?User $actor = null,
    ): void {
        $actor ??= app()->bound('request') && request()->user() instanceof User
            ? request()->user()
            : null;

        $transition = DB::transaction(function () use ($application, $interviewData, $actor): array {
            $locked = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
            $previousStage = $locked->stage;
            $next = $previousStage->next();
            if (! $next) {
                throw new BusinessRuleException("Cannot advance from terminal stage: {$previousStage->value}");
            }

            RecruitmentApplicationStateMachine::assertCanTransition($previousStage, $next);

            if ($next === ApplicationStage::Hired
                && (! $actor || ! $actor->hasPermission('hr.recruitment.hire'))) {
                throw new AuthorizationException('You do not have permission to mark an application hired.');
            }

            if ($previousStage === ApplicationStage::Screening && ! $interviewData) {
                throw new BusinessRuleException('Interview data required when advancing to interview stage.');
            }

            // A pending interview is not a decision. A passed outcome is the
            // explicit gate for moving from interview to offer.
            if ($previousStage === ApplicationStage::Interview
                && ! $locked->interviews()->where('outcome', InterviewOutcome::Passed->value)->exists()) {
                throw new BusinessRuleException('At least one interview must be marked passed before advancing to offer.');
            }

            $locked->stage = $next;
            $locked->save();

            $interview = null;
            if ($interviewData && $next === ApplicationStage::Interview) {
                $interview = $this->createInterviewRecord($locked, $interviewData, $actor);
            }

            $this->recordApplicationEvent(
                $locked,
                'stage.advanced',
                $actor,
                ['stage' => $previousStage->value],
                ['stage' => $next->value],
                $previousStage,
                $next,
            );

            return [
                'previous' => $previousStage,
                'next' => $next,
                'interview' => $interview,
            ];
        });

        $application->refresh();

        if ($transition['interview'] instanceof ApplicationInterview) {
            $this->queueInterviewScheduledMail(
                $application->load('jobPosting'),
                $transition['interview'],
            );
        } else {
            $this->queueApplicationStatusEmail(
                $application,
                $transition['previous'],
                $transition['next'],
            );
        }
    }

    public function rejectApplication(
        JobApplication $application,
        ?string $reason = null,
        ?User $actor = null,
    ): void {
        $previousStage = DB::transaction(function () use ($application, $reason, $actor): ApplicationStage {
            $locked = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
            $previousStage = $locked->stage;
            if ($previousStage->isTerminal()) {
                throw new BusinessRuleException("Cannot reject from terminal stage: {$previousStage->value}");
            }

            RecruitmentApplicationStateMachine::assertCanTransition($previousStage, ApplicationStage::Rejected);

            $locked->rejected_at_stage = $previousStage->value;
            $locked->rejection_reason = $reason;
            $locked->stage = ApplicationStage::Rejected;
            $locked->save();

            $this->recordApplicationEvent(
                $locked,
                'application.rejected',
                $actor,
                ['stage' => $previousStage->value],
                [
                    'stage' => ApplicationStage::Rejected->value,
                    'has_reason' => $reason !== null && trim($reason) !== '',
                ],
                $previousStage,
                ApplicationStage::Rejected,
            );

            return $previousStage;
        });

        $application->refresh();
        $this->queueApplicationStatusEmail($application, $previousStage, ApplicationStage::Rejected);
    }

    public function scheduleInterview(
        JobApplication $application,
        array $data,
        ?User $actor = null,
    ): ApplicationInterview {
        $interview = DB::transaction(function () use ($application, $data, $actor): ApplicationInterview {
            $locked = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($locked->stage !== ApplicationStage::Interview) {
                throw new BusinessRuleException("Interviews can only be scheduled at the interview stage, current: {$locked->stage->value}");
            }

            return $this->createInterviewRecord($locked, $data, $actor);
        });

        $application->refresh();
        $this->queueInterviewScheduledMail($application->load('jobPosting'), $interview);

        return $interview;
    }

    public function updateInterview(
        ApplicationInterview $interview,
        array $data,
        ?User $actor = null,
    ): void {
        $result = DB::transaction(function () use ($interview, $data, $actor): array {
            $locked = ApplicationInterview::query()
                ->lockForUpdate()
                ->findOrFail($interview->id);
            $locked->loadMissing('application.jobPosting');

            $before = [
                'interview_id' => $locked->hash_id,
                'outcome' => $locked->outcome?->value,
                'scheduled_at' => $locked->scheduled_at?->toIso8601String(),
            ];

            $outcomeProvided = array_key_exists('outcome', $data);
            $outcome = $data['outcome'] ?? null;
            unset($data['outcome']);
            $locked->fill($data);
            if ($outcomeProvided) {
                // The outcome is intentionally guarded on the model; only this
                // workflow service may record an interview decision.
                $locked->forceFill(['outcome' => $outcome]);
            }

            $candidateVisibleChange = collect([
                'scheduled_at',
                'location',
                'interviewer_name',
                'outcome',
            ])->contains(fn (string $field): bool => $locked->isDirty($field));

            if ($locked->isDirty()) {
                $locked->save();
                $this->recordApplicationEvent(
                    $locked->application,
                    'interview.updated',
                    $actor,
                    $before,
                    [
                        'interview_id' => $locked->hash_id,
                        'outcome' => $locked->outcome?->value,
                        'scheduled_at' => $locked->scheduled_at?->toIso8601String(),
                    ],
                    $locked->application->stage,
                    $locked->application->stage,
                    ['candidate_visible' => $candidateVisibleChange],
                );
            }

            return [
                'interview' => $locked->fresh()->load('application.jobPosting'),
                'candidate_visible_change' => $candidateVisibleChange,
            ];
        });

        if ($result['candidate_visible_change']) {
            $this->queueInterviewUpdatedMail(
                $result['interview']->application,
                $result['interview'],
            );
        }
    }

    public function addNote(JobApplication $application, string $body, User $user): ApplicationNote
    {
        return DB::transaction(function () use ($application, $body, $user): ApplicationNote {
            $locked = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
            $note = ApplicationNote::create([
                'job_application_id' => $locked->id,
                'user_id' => $user->id,
                'body' => $body,
            ]);

            $this->recordApplicationEvent(
                $locked,
                'note.added',
                $user,
                null,
                ['note_id' => $note->hash_id],
                $locked->stage,
                $locked->stage,
            );

            return $note;
        });
    }

    public function getTrackingInfo(string $trackingCode): ?array
    {
        $app = JobApplication::with(['jobPosting:id,title', 'interviews' => function ($q) {
            $q->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->limit(1);
        }])->where('tracking_code', strtoupper(trim($trackingCode)))->first();

        if (! $app) {
            return null;
        }

        $interview = $app->interviews->first();
        $statusLabel = $app->stage->publicLabel();
        if ($app->stage === ApplicationStage::Interview && $interview) {
            $statusLabel = 'Interview Scheduled';
        }

        return [
            'tracking_code' => $app->tracking_code,
            'position' => $app->jobPosting->title,
            'applied_at' => $app->applied_at->toIso8601String(),
            'status' => $statusLabel,
            'stage_steps' => array_values(array_map(
                static fn (ApplicationStage $stage): array => ['value' => $stage->value, 'label' => $stage->publicLabel()],
                array_filter(
                    ApplicationStage::cases(),
                    static fn (ApplicationStage $stage): bool => $stage !== ApplicationStage::Rejected,
                ),
            )),
            'interview' => $interview ? [
                'scheduled_at' => $interview->scheduled_at->toIso8601String(),
                'location' => $interview->location,
            ] : null,
        ];
    }

    public function getConversionData(JobApplication $application): array
    {
        $application->load('jobPosting.department', 'jobPosting.position');
        $posting = $application->jobPosting;

        return [
            'first_name' => $application->first_name,
            'last_name' => $application->last_name,
            'email' => $application->email,
            'phone' => $application->phone,
            'department_id' => $posting->department?->hash_id,
            'position_id' => $posting->position?->hash_id,
        ];
    }

    public function markConverted(
        JobApplication $application,
        Employee $employee,
        ?User $actor = null,
    ): void {
        DB::transaction(function () use ($application, $employee, $actor): void {
            $lockedApplication = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($lockedApplication->stage !== ApplicationStage::Hired) {
                throw new BusinessRuleException('Only hired applications can be converted.');
            }

            if ($lockedApplication->converted_employee_id) {
                if ((int) $lockedApplication->converted_employee_id !== (int) $employee->id) {
                    throw new BusinessRuleException('This application is already linked to another employee.');
                }

                return;
            }

            $posting = JobPosting::withTrashed()
                ->lockForUpdate()
                ->findOrFail($lockedApplication->job_posting_id);

            $lockedApplication->converted_employee_id = $employee->id;
            $lockedApplication->save();

            $hiredCount = JobApplication::query()
                ->where('job_posting_id', $posting->id)
                ->where('stage', ApplicationStage::Hired->value)
                ->whereNotNull('converted_employee_id')
                ->count();

            if ($hiredCount >= $posting->slots && $posting->status !== JobPostingStatus::Filled) {
                if (! $posting->status->canTransitionTo(JobPostingStatus::Filled)) {
                    throw new BusinessRuleException("Cannot mark posting as filled from {$posting->status->value}.");
                }

                $posting->status = JobPostingStatus::Filled;
                $posting->save();
            }

            $this->recordApplicationEvent(
                $lockedApplication,
                'application.converted',
                $actor,
                ['converted_employee_id' => null],
                ['converted_employee_id' => $employee->hash_id],
                ApplicationStage::Hired,
                ApplicationStage::Hired,
                ['employee_id' => $employee->hash_id],
            );
        });
    }

    private function createInterviewRecord(
        JobApplication $application,
        array $data,
        ?User $actor = null,
    ): ApplicationInterview {
        $interview = ApplicationInterview::create($data + [
            'job_application_id' => $application->id,
        ]);

        $this->recordApplicationEvent(
            $application,
            'interview.scheduled',
            $actor,
            null,
            [
                'interview_id' => $interview->hash_id,
                'scheduled_at' => $interview->scheduled_at?->toIso8601String(),
                'location' => $interview->location,
            ],
            $application->stage,
            $application->stage,
        );

        return $interview;
    }

    private function queueInterviewScheduledMail(
        JobApplication $application,
        ApplicationInterview $interview,
    ): void {
        try {
            Mail::to($application->email)->queue(
                new InterviewScheduledMail($application, $interview, $this->hrUserIds())
            );
        } catch (\Throwable $e) {
            Log::warning('Interview was saved but its candidate email failed.', [
                'application_id' => $application->id,
                'interview_id' => $interview->id,
                'error' => $e->getMessage(),
            ]);

            app(EmailDeliveryFailureNotifier::class)->notify(
                $this->hrUsers(),
                'Recruitment interview notification',
                "The interview email for {$application->full_name} could not be delivered. Review the interview and contact the candidate through an approved channel.",
                [
                    'link_to' => "/hr/recruitment/applications/{$application->hash_id}",
                    'entity_type' => 'job_application',
                    'entity_id' => $application->hash_id,
                    'reason' => 'The candidate email address was unreachable or the email provider rejected the message.',
                ],
            );
        }
    }

    private function queueInterviewUpdatedMail(
        JobApplication $application,
        ApplicationInterview $interview,
    ): void {
        try {
            Mail::to($application->email)->queue(
                new InterviewDetailsUpdatedMail(
                    $application,
                    $interview,
                    $this->hrUserIds(),
                )
            );
        } catch (\Throwable $e) {
            Log::warning('Interview was updated but its candidate email could not be queued.', [
                'application_id' => $application->id,
                'interview_id' => $interview->id,
                'error' => $e->getMessage(),
            ]);

            app(EmailDeliveryFailureNotifier::class)->notify(
                $this->hrUsers(),
                'Recruitment interview update',
                "The interview update email for {$application->full_name} could not be queued. Review the interview and contact the candidate through an approved channel.",
                [
                    'link_to' => "/hr/recruitment/applications/{$application->hash_id}",
                    'entity_type' => 'job_application',
                    'entity_id' => $application->hash_id,
                    'reason' => 'The candidate email address was unreachable or the email provider rejected the message.',
                ],
            );
        }
    }

    /**
     * Store only workflow evidence. Candidate PII and note bodies stay in their
     * source tables and are governed by those tables' retention controls.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $metadata
     */
    private function recordApplicationEvent(
        JobApplication $application,
        string $eventType,
        ?User $actor,
        ?array $before,
        ?array $after,
        ?ApplicationStage $fromStage,
        ?ApplicationStage $toStage,
        array $metadata = [],
    ): void {
        $request = app()->bound('request') ? request() : null;
        $actor ??= $request?->user();

        RecruitmentApplicationEvent::create([
            'job_application_id' => $application->id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor ? 'user' : 'system',
            'event_type' => $eventType,
            'from_stage' => $fromStage?->value,
            'to_stage' => $toStage?->value,
            'before_values' => $before,
            'after_values' => $after,
            'metadata' => $metadata !== [] ? $metadata : null,
            'correlation_id' => $request?->attributes->get('request_id')
                ?? $request?->header('X-Request-ID'),
            'created_at' => now(),
        ]);
    }

    private function generateTrackingCode(): string
    {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = 'RCT-';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            if (! JobApplication::where('tracking_code', $code)->exists()) {
                return $code;
            }
        }
        // Five collisions on a 31^6 space is a bug or a corrupt index, never the
        // applicant's doing. Left unmapped.
        throw new \RuntimeException('Failed to generate unique tracking code after 5 attempts');
    }

    private function notifyHrNewApplication(JobApplication $application, JobPosting $posting): void
    {
        $hrUsers = $this->hrUsers();

        if ($hrUsers->isEmpty()) {
            return;
        }

        $this->notifications->send($hrUsers, 'recruitment.new_application', [
            'title' => 'New Job Application',
            'message' => "{$application->full_name} applied for {$posting->title}",
            'link_to' => "/hr/recruitment/applications/{$application->hash_id}",
            'entity_type' => 'job_application',
            'entity_id' => $application->hash_id,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function hrUsers(): \Illuminate\Support\Collection
    {
        return RecruitmentNotificationRecipients::resolve($this->settings);
    }

    /** @return list<int> */
    private function hrUserIds(): array
    {
        return $this->hrUsers()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function queueApplicationStatusEmail(
        JobApplication $application,
        ApplicationStage $previousStage,
        ApplicationStage $currentStage,
    ): void {
        $application->loadMissing('jobPosting');

        try {
            Mail::to($application->email)->queue(
                new ApplicationStatusUpdatedMail(
                    $application->full_name,
                    $application->jobPosting->title,
                    $previousStage->value,
                    $currentStage->value,
                    $application->tracking_code,
                    $application->hash_id,
                    $this->hrUserIds(),
                )
            );
        } catch (\Throwable $e) {
            Log::warning('Application status changed but its candidate email could not be queued.', [
                'application_id' => $application->id,
                'previous_stage' => $previousStage->value,
                'current_stage' => $currentStage->value,
                'error' => $e->getMessage(),
            ]);

            app(EmailDeliveryFailureNotifier::class)->notify(
                $this->hrUsers(),
                'Recruitment application update',
                "The status update email for {$application->full_name} could not be queued. Review the application and contact the candidate through an approved channel.",
                [
                    'link_to' => "/hr/recruitment/applications/{$application->hash_id}",
                    'entity_type' => 'job_application',
                    'entity_id' => $application->hash_id,
                    'reason' => 'The candidate email address was unreachable or the email provider rejected the message.',
                ],
            );
        }
    }

    private function isDuplicateApplicationEmailViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && str_contains(strtolower($exception->getMessage()), self::DUPLICATE_EMAIL_INDEX);
    }
}
