<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Exceptions\BusinessRuleException;
use Throwable;

/** Converts planning failures into stable, operator-safe run diagnostics. */
final class MrpErrorPolicy
{
    /** @return array{message:string, code:string, recovery_action:string} */
    public static function describe(Throwable $exception): array
    {
        if ($exception instanceof BusinessRuleException) {
            return [
                'message' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'MRP could not evaluate this sales order because a planning rule was not satisfied.',
                'code' => $exception->errorCode() ?? 'mrp_business_rule',
                'recovery_action' => self::recoveryFor($exception->errorCode()),
            ];
        }

        return [
            'message' => 'MRP could not evaluate this sales order because of an internal planning error.',
            'code' => 'mrp_internal_error',
            'recovery_action' => 'Review the run logs with the run number, correct the source data if needed, then rerun MRP.',
        ];
    }

    private static function recoveryFor(?string $code): string
    {
        return match ($code) {
            'missing_bom', 'bom_component_integrity', 'bom_structure' =>
                'Correct or complete the affected BOM, then rerun MRP.',
            default => 'Correct the referenced planning data, then rerun MRP.',
        };
    }
}
