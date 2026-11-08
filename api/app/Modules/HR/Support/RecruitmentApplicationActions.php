<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\InterviewOutcome;
use App\Modules\HR\Models\JobApplication;

/** Computes application actions from the persisted state and current viewer. */
final class RecruitmentApplicationActions
{
    /** @return list<string> */
    public static function for(JobApplication $application, ?User $user): array
    {
        $canManage = $user?->hasPermission('hr.recruitment.applications') ?? false;
        $canHire = $user?->hasPermission('hr.recruitment.hire') ?? false;
        $stage = $application->stage;
        $actions = [];

        if ($stage->next() !== null
            && ($stage === ApplicationStage::Offer ? $canHire : $canManage)
            && ($stage !== ApplicationStage::Interview || self::hasPassedInterview($application))) {
            $actions[] = 'advance';
        }

        if (! $stage->isTerminal() && $canManage) {
            $actions[] = 'reject';
        }

        if ($stage === ApplicationStage::Interview && $canManage) {
            $actions[] = 'schedule_interview';
            if (self::hasInterviews($application)) {
                $actions[] = 'update_interview_outcome';
            }
        }

        if ($canManage) {
            $actions[] = 'add_note';
        }

        if ($stage === ApplicationStage::Hired && $canHire && $application->converted_employee_id === null) {
            $actions[] = 'convert_to_employee';
        }

        return $actions;
    }

    /** @return array{view:bool,manage_applications:bool,hire:bool} */
    public static function permissions(?User $user): array
    {
        return [
            'view' => $user?->hasPermission('hr.recruitment.view') ?? false,
            'manage_applications' => $user?->hasPermission('hr.recruitment.applications') ?? false,
            'hire' => $user?->hasPermission('hr.recruitment.hire') ?? false,
        ];
    }

    private static function hasPassedInterview(JobApplication $application): bool
    {
        if ($application->relationLoaded('interviews')) {
            return $application->interviews->contains(
                static fn ($interview): bool => $interview->outcome === InterviewOutcome::Passed
                    || $interview->outcome === InterviewOutcome::Passed->value,
            );
        }

        if (array_key_exists('has_passed_interview', $application->getAttributes())) {
            return (bool) $application->getAttribute('has_passed_interview');
        }

        return $application->interviews()
            ->where('outcome', InterviewOutcome::Passed->value)
            ->exists();
    }

    private static function hasInterviews(JobApplication $application): bool
    {
        if ($application->relationLoaded('interviews')) {
            return $application->interviews->isNotEmpty();
        }

        if (array_key_exists('has_interviews', $application->getAttributes())) {
            return (bool) $application->getAttribute('has_interviews');
        }

        return $application->interviews()->exists();
    }
}
