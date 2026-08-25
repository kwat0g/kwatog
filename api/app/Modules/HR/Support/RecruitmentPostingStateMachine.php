<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\HR\Enums\JobPostingStatus;

/** The single legal transition table for recruitment posting statuses. */
final class RecruitmentPostingStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        JobPostingStatus::Draft->value => [JobPostingStatus::Open->value],
        JobPostingStatus::Open->value => [
            JobPostingStatus::Closed->value,
            JobPostingStatus::Filled->value,
        ],
        JobPostingStatus::Closed->value => [JobPostingStatus::Open->value],
        JobPostingStatus::Filled->value => [],
    ];

    public static function canTransition(JobPostingStatus $from, JobPostingStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public static function assertCanTransition(JobPostingStatus $from, JobPostingStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new BusinessRuleException(
                "Cannot transition posting from {$from->value} to {$to->value}.",
            );
        }
    }
}
