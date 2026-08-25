<?php

declare(strict_types=1);

namespace App\Modules\Quality\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Quality\Enums\EffectivenessStatus;

/**
 * Server-authoritative CAPA verification transitions.
 *
 * An ineffective action is deliberately re-checkable after its follow-up
 * date. Effective and not-applicable are terminal until a future, explicit
 * reopen workflow is added; no endpoint may silently rewrite either verdict.
 */
final class NcrEffectivenessStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'pending_verification' => ['effective', 'ineffective', 'not_applicable'],
        'ineffective'          => ['effective', 'ineffective', 'not_applicable'],
        'effective'            => [],
        'not_applicable'       => [],
    ];

    public static function assertCanTransition(
        ?EffectivenessStatus $from,
        EffectivenessStatus $to,
    ): void {
        if ($from === null || ! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            $fromLabel = $from?->label() ?? 'Unscheduled';
            throw new BusinessRuleException(
                "CAPA effectiveness cannot move from {$fromLabel} to {$to->label()}."
            );
        }
    }
}
