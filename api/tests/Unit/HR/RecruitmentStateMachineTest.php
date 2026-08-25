<?php

declare(strict_types=1);

namespace Tests\Unit\HR;

use App\Modules\HR\Enums\ApplicationStage;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Support\RecruitmentApplicationStateMachine;
use App\Modules\HR\Support\RecruitmentPostingStateMachine;
use PHPUnit\Framework\TestCase;

final class RecruitmentStateMachineTest extends TestCase
{
    public function test_application_transitions_are_explicit(): void
    {
        self::assertSame(ApplicationStage::Screening, ApplicationStage::New->next());
        self::assertTrue(RecruitmentApplicationStateMachine::canTransition(
            ApplicationStage::Offer,
            ApplicationStage::Hired,
        ));
        self::assertFalse(RecruitmentApplicationStateMachine::canTransition(
            ApplicationStage::Hired,
            ApplicationStage::Offer,
        ));
    }

    public function test_posting_transitions_are_explicit(): void
    {
        self::assertTrue(RecruitmentPostingStateMachine::canTransition(
            JobPostingStatus::Draft,
            JobPostingStatus::Open,
        ));
        self::assertFalse(RecruitmentPostingStateMachine::canTransition(
            JobPostingStatus::Filled,
            JobPostingStatus::Open,
        ));
    }
}
