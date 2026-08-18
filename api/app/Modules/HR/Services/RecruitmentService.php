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
        $posting->update($data);

        return $posting->fresh();
    }

    public function changePostingStatus(JobPosting $posting, JobPostingStatus $newStatus): void
    {
        if (! $posting->status->canTransitionTo($newStatus)) {
            throw new BusinessRuleException("Cannot transition posting from {$posting->status->value} to {$newStatus->value}.");
        }

        if ($newStatus === JobPostingStatus::Open && ! $posting->posted_at) {
            $posting->posted_at = now();
        }
        $posting->status = $newStatus;
        $posting->save();
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
            $application = DB::transaction(function () use ($posting, $data, $resume, $path) {
                $application = new JobApplication;
                $application->fill([
                    'application_number' => $this->sequences->generate('job_application'),
                    'job_posting_id' => $posting->id,
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
            // HR must still see every saved application when Brevo is down.
            $this->notifyHrNewApplication($application, $posting);
        } catch (\Throwable $e) {
            Log::warning('Application saved but HR inbox notification failed.', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $application;
    }

    public function advanceStage(JobApplication $application, ?array $interviewData = null): void
    {
        $previousStage = $application->stage;
        $next = $application->stage->next();
        if (! $next) {
            throw new BusinessRuleException("Cannot advance from terminal stage: {$application->stage->value}");
        }

        if ($application->stage === ApplicationStage::Screening && ! $interviewData) {
            throw new BusinessRuleException('Interview data required when advancing to interview stage.');
        }

        DB::transaction(function () use ($application, $next, $interviewData) {
            $application->stage = $next;
            $application->save();

            if ($interviewData && $next === ApplicationStage::Interview) {
                $this->scheduleInterview($application, $interviewData);
            }
        });

        // Interview scheduling has its own candidate-facing message. All
        // other stage changes need an explicit status update so a candidate
        // does not have to keep polling the tracking page to discover a
        // screening, offer, or hiring decision.
        if ($next !== ApplicationStage::Interview) {
            $this->queueApplicationStatusEmail($application->fresh(), $previousStage, $next);
        }
    }

    public function rejectApplication(JobApplication $application, ?string $reason = null): void
    {
        $previousStage = $application->stage;
        if ($application->stage->isTerminal()) {
            throw new BusinessRuleException("Cannot reject from terminal stage: {$application->stage->value}");
        }
        $application->rejected_at_stage = $application->stage->value;
        $application->rejection_reason = $reason;
        $application->stage = ApplicationStage::Rejected;
        $application->save();

        $this->queueApplicationStatusEmail($application->fresh(), $previousStage, ApplicationStage::Rejected);
    }

    public function scheduleInterview(JobApplication $application, array $data): ApplicationInterview
    {
        if ($application->stage !== ApplicationStage::Interview) {
            throw new BusinessRuleException("Interviews can only be scheduled at the interview stage, current: {$application->stage->value}");
        }

        $interview = ApplicationInterview::create($data + [
            'job_application_id' => $application->id,
        ]);

        $application->load('jobPosting');

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

        return $interview;
    }

    public function updateInterview(ApplicationInterview $interview, array $data): void
    {
        $interview->loadMissing('application.jobPosting');
        $outcomeProvided = array_key_exists('outcome', $data);
        $outcome = $data['outcome'] ?? null;
        unset($data['outcome']);
        $interview->fill($data);
        if ($outcomeProvided) {
            // `outcome` is intentionally guarded on the model; only this
            // workflow service may record an interview decision.
            $interview->forceFill(['outcome' => $outcome]);
        }

        $candidateVisibleChange = collect([
            'scheduled_at',
            'location',
            'interviewer_name',
            'outcome',
        ])->contains(fn (string $field): bool => $interview->isDirty($field));
        $interview->save();

        if ($candidateVisibleChange) {
            try {
                Mail::to($interview->application->email)->queue(
                    new InterviewDetailsUpdatedMail(
                        $interview->application,
                        $interview,
                        $this->hrUserIds(),
                    )
                );
            } catch (\Throwable $e) {
                Log::warning('Interview was updated but its candidate email could not be queued.', [
                    'application_id' => $interview->application_id,
                    'interview_id' => $interview->id,
                    'error' => $e->getMessage(),
                ]);

                app(EmailDeliveryFailureNotifier::class)->notify(
                    $this->hrUsers(),
                    'Recruitment interview update',
                    "The interview update email for {$interview->application->full_name} could not be queued. Review the interview and contact the candidate through an approved channel.",
                    [
                        'link_to' => "/hr/recruitment/applications/{$interview->application->hash_id}",
                        'entity_type' => 'job_application',
                        'entity_id' => $interview->application->hash_id,
                        'reason' => 'The candidate email address was unreachable or the email provider rejected the message.',
                    ],
                );
            }
        }
    }

    public function addNote(JobApplication $application, string $body, User $user): ApplicationNote
    {
        return ApplicationNote::create([
            'job_application_id' => $application->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);
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
                array_filter(ApplicationStage::cases(), static fn (ApplicationStage $stage): bool => ! $stage->isTerminal()),
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

    public function markConverted(JobApplication $application, Employee $employee): void
    {
        $application->converted_employee_id = $employee->id;
        $application->save();

        $posting = $application->jobPosting;
        $hiredCount = $posting->applications()
            ->where('stage', ApplicationStage::Hired->value)
            ->whereNotNull('converted_employee_id')
            ->count();

        if ($hiredCount >= $posting->slots) {
            $this->changePostingStatus($posting, JobPostingStatus::Filled);
        }
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
        $roles = array_values(array_filter(
            (array) $this->settings->get('hr.recruitment.notification_roles', ['hr_officer', 'system_admin']),
            static fn ($role): bool => is_string($role) && $role !== '',
        ));

        // An explicitly empty setting must not disable the only fallback for
        // recruitment alerts. Administrators can still narrow this list by
        // configuring one or more concrete roles.
        if ($roles === []) {
            $roles = ['hr_officer', 'system_admin'];
        }

        return User::whereHas('role', function ($q) use ($roles): void {
            $q->whereIn('slug', $roles);
        })->where('is_active', true)->get();
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
