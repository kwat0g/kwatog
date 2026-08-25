<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\HR\Enums\ApplicationStage;

/** The single legal transition table for recruitment application stages. */
final class RecruitmentApplicationStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        ApplicationStage::New->value => [
            ApplicationStage::Screening->value,
            ApplicationStage::Rejected->value,
        ],
        ApplicationStage::Screening->value => [
            ApplicationStage::Interview->value,
            ApplicationStage::Rejected->value,
        ],
        ApplicationStage::Interview->value => [
            ApplicationStage::Offer->value,
            ApplicationStage::Rejected->value,
        ],
        ApplicationStage::Offer->value => [
            ApplicationStage::Hired->value,
            ApplicationStage::Rejected->value,
        ],
        ApplicationStage::Hired->value => [],
        ApplicationStage::Rejected->value => [],
    ];

    public static function next(ApplicationStage $from): ?ApplicationStage
    {
        foreach (self::TRANSITIONS[$from->value] ?? [] as $target) {
            if ($target !== ApplicationStage::Rejected->value) {
                return ApplicationStage::from($target);
            }
        }

        return null;
    }

    public static function canTransition(ApplicationStage $from, ApplicationStage $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public static function assertCanTransition(ApplicationStage $from, ApplicationStage $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new BusinessRuleException(
                "Cannot transition application from {$from->value} to {$to->value}.",
            );
        }
    }
}
