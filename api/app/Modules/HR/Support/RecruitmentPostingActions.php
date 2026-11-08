<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\JobPosting;

/** Computes job-posting actions from persisted state and the current viewer. */
final class RecruitmentPostingActions
{
    /** @return list<string> */
    public static function for(JobPosting $posting, ?User $user): array
    {
        if (! ($user?->hasPermission('hr.recruitment.manage') ?? false)) {
            return [];
        }

        if ($posting->trashed()) {
            return ['restore'];
        }

        $actions = ['edit'];

        if ($posting->status === JobPostingStatus::Draft
            && RecruitmentPostingStateMachine::canTransition($posting->status, JobPostingStatus::Open)) {
            $actions[] = 'publish';
        }

        if ($posting->status === JobPostingStatus::Open
            && RecruitmentPostingStateMachine::canTransition($posting->status, JobPostingStatus::Closed)) {
            $actions[] = 'close';
        }

        if ($posting->status === JobPostingStatus::Draft && ! self::hasApplications($posting)) {
            $actions[] = 'archive';
        }

        return $actions;
    }

    /** @return array{view:bool,manage:bool} */
    public static function permissions(?User $user): array
    {
        return [
            'view' => $user?->hasPermission('hr.recruitment.view') ?? false,
            'manage' => $user?->hasPermission('hr.recruitment.manage') ?? false,
        ];
    }

    private static function hasApplications(JobPosting $posting): bool
    {
        if (array_key_exists('applications_count', $posting->getAttributes())) {
            return (int) $posting->getAttribute('applications_count') > 0;
        }

        return $posting->applications()->exists();
    }
}
