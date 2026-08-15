<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds recruitment records that can remain waiting forever without a
 * visible operator prompt. This command never changes candidate or posting
 * state; it raises a deduplicated HR inbox notification instead.
 */
class CheckRecruitmentBottlenecks extends Command
{
    protected $signature = 'recruitment:check-bottlenecks
        {--new-days=2 : Days a new application may wait before an HR reminder}
        {--screening-days=3 : Days screening may remain open before an HR reminder}
        {--interview-days=2 : Days an interview-stage application may wait without an upcoming interview}
        {--offer-days=5 : Days an offer-stage application may wait before an HR reminder}
        {--hired-days=3 : Days a hired application may wait without employee conversion}
        {--dedup-hours=24 : Hours before the same bottleneck can notify HR again}
        {--dry-run : Report bottlenecks without writing notifications}';

    protected $description = 'Scan recruitment applications, interviews, and postings for stuck workflow states';

    public function handle(SettingsService $settings, NotificationService $notifications): int
    {
        $thresholds = [
            ApplicationStage::New->value => $this->daysOption('new-days'),
            ApplicationStage::Screening->value => $this->daysOption('screening-days'),
            ApplicationStage::Interview->value => $this->daysOption('interview-days'),
            ApplicationStage::Offer->value => $this->daysOption('offer-days'),
            ApplicationStage::Hired->value => $this->daysOption('hired-days'),
        ];
        $dedupHours = max(1, (int) $this->option('dedup-hours'));
        $recipients = $this->hrUsers($settings);

        if ($recipients->isEmpty() && ! $this->option('dry-run')) {
            $this->warn('No active HR recruitment notification recipients were found.');

            return self::FAILURE;
        }

        if ($recipients->isEmpty()) {
            $this->warn('Dry run has no active HR recipients; bottlenecks will be reported without notifications.');
        }

        $raised = 0;
        $raised += $this->scanApplications($recipients, $notifications, $thresholds, $dedupHours);
        $raised += $this->scanExpiredPostings($recipients, $notifications, $dedupHours);

        $this->info(sprintf(
            'Recruitment bottleneck scan completed — %d new alert(s)%s.',
            $raised,
            $this->option('dry-run') ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    /** @param array<string, int> $thresholds */
    private function scanApplications(
        Collection $recipients,
        NotificationService $notifications,
        array $thresholds,
        int $dedupHours,
    ): int {
        $count = 0;
        $stages = array_keys($thresholds);

        JobApplication::query()
            ->with(['jobPosting:id,title', 'interviews:id,job_application_id,scheduled_at,outcome'])
            ->whereIn('stage', $stages)
            ->orderBy('id')
            ->chunkById(100, function (Collection $applications) use (&$count, $recipients, $notifications, $thresholds, $dedupHours): void {
                foreach ($applications as $application) {
                    $stage = $application->stage?->value ?? (string) $application->getRawOriginal('stage');
                    $ageDays = $application->updated_at?->diffInDays(now()) ?? 0;
                    $reason = null;

                    if ($stage === ApplicationStage::Interview->value) {
                        $futureInterview = $application->interviews
                            ->filter(fn (ApplicationInterview $interview): bool => $interview->scheduled_at?->isFuture() ?? false)
                            ->sortBy('scheduled_at')
                            ->first();

                        $overdueWithoutOutcome = $application->interviews
                            ->filter(fn (ApplicationInterview $interview): bool =>
                                ($interview->scheduled_at?->isPast() ?? false) && $interview->outcome === null
                            )
                            ->sortByDesc('scheduled_at')
                            ->first();

                        if ($overdueWithoutOutcome) {
                            $reason = 'An interview has passed without a recorded outcome.';
                        } elseif (! $futureInterview && $ageDays >= $thresholds[$stage]) {
                            $reason = 'The application is at interview stage without an upcoming interview.';
                        }
                    } elseif ($ageDays >= $thresholds[$stage]) {
                        $reason = match ($stage) {
                            ApplicationStage::New->value => 'The application is new and has not been moved to screening.',
                            ApplicationStage::Screening->value => 'Screening has not been completed.',
                            ApplicationStage::Offer->value => 'The offer stage has not been completed.',
                            ApplicationStage::Hired->value => 'The candidate is marked hired but has not been converted to an employee.',
                            default => 'The application has not progressed.',
                        };
                    }

                    if ($reason === null) {
                        continue;
                    }

                    $dedupeKey = 'application:'.$application->id.':'.$stage.':'.sha1($reason);
                    if ($this->raise($recipients, $notifications, $dedupeKey, 'job_application', $application->hash_id, 'Recruitment bottleneck', $application->full_name.' — '.$reason, '/hr/recruitment/applications/'.$application->hash_id, $dedupHours)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function scanExpiredPostings(Collection $recipients, NotificationService $notifications, int $dedupHours): int
    {
        $count = 0;

        JobPosting::query()
            ->where('status', JobPostingStatus::Open->value)
            ->whereNotNull('closes_at')
            ->where('closes_at', '<', now())
            ->orderBy('id')
            ->chunkById(100, function (Collection $postings) use (&$count, $recipients, $notifications, $dedupHours): void {
                foreach ($postings as $posting) {
                    $dedupeKey = 'posting:'.$posting->id.':expired';
                    if ($this->raise($recipients, $notifications, $dedupeKey, 'job_posting', $posting->hash_id, 'Recruitment posting expired', "The open posting {$posting->posting_number} — {$posting->title} has passed its closing date and should be closed or extended.", '/hr/recruitment/postings/'.$posting->hash_id, $dedupHours)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function raise(
        Collection $recipients,
        NotificationService $notifications,
        string $dedupeKey,
        string $entityType,
        string $entityId,
        string $title,
        string $message,
        string $linkTo,
        int $dedupHours,
    ): bool {
        if ($this->alreadyRaised($dedupeKey, $dedupHours)) {
            return false;
        }

        $this->line("[BOTTLENECK] {$message}");
        if ($this->option('dry-run')) {
            return true;
        }

        $notifications->sendInApp($recipients, 'recruitment.bottleneck', [
            'title' => $title,
            'message' => $message,
            'link_to' => $linkTo,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'dedupe_key' => $dedupeKey,
        ]);

        return true;
    }

    private function alreadyRaised(string $dedupeKey, int $dedupHours): bool
    {
        return DB::table('notifications')
            ->where('type', 'recruitment.bottleneck')
            ->where('created_at', '>=', now()->subHours($dedupHours))
            ->whereJsonContains('data->dedupe_key', $dedupeKey)
            ->exists();
    }

    /** @return Collection<int, User> */
    private function hrUsers(SettingsService $settings): Collection
    {
        $roles = array_values(array_filter(
            (array) $settings->get('hr.recruitment.notification_roles', ['hr_officer', 'system_admin']),
            static fn ($role): bool => is_string($role) && $role !== '',
        ));

        if ($roles === []) {
            $roles = ['hr_officer', 'system_admin'];
        }

        return User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('slug', $roles))
            ->where('is_active', true)
            ->get();
    }

    private function daysOption(string $name): int
    {
        $value = (int) $this->option($name);

        if ($value < 1 || $value > 365) {
            throw new \InvalidArgumentException("--{$name} must be between 1 and 365.");
        }

        return $value;
    }
}
