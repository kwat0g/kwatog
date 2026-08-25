<?php

declare(strict_types=1);

namespace Tests\Unit\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Support\NcrEffectivenessStateMachine;
use PHPUnit\Framework\TestCase;

final class NcrEffectivenessStateMachineTest extends TestCase
{
    public function test_pending_verification_can_become_effective(): void
    {
        NcrEffectivenessStateMachine::assertCanTransition(
            EffectivenessStatus::PendingVerification,
            EffectivenessStatus::Effective,
        );

        $this->addToAssertionCount(1);
    }

    public function test_ineffective_can_be_rechecked(): void
    {
        NcrEffectivenessStateMachine::assertCanTransition(
            EffectivenessStatus::Ineffective,
            EffectivenessStatus::Ineffective,
        );

        $this->addToAssertionCount(1);
    }

    public function test_terminal_verdict_cannot_be_overwritten(): void
    {
        $this->expectException(BusinessRuleException::class);

        NcrEffectivenessStateMachine::assertCanTransition(
            EffectivenessStatus::Effective,
            EffectivenessStatus::Ineffective,
        );
    }

    public function test_unscheduled_action_cannot_be_verified(): void
    {
        $this->expectException(BusinessRuleException::class);

        NcrEffectivenessStateMachine::assertCanTransition(
            null,
            EffectivenessStatus::Effective,
        );
    }
}
