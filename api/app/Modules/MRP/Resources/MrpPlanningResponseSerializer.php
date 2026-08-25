<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

/** Keeps nested planning diagnostics on the public hash-id contract. */
final class MrpPlanningResponseSerializer
{
    /** @param mixed $diagnostics @return array<int, array<string, mixed>> */
    public static function diagnostics(mixed $diagnostics): array
    {
        return self::sanitizeList($diagnostics);
    }

    /** @param mixed $summary @return array<string, mixed> */
    public static function summary(mixed $summary): array
    {
        $sanitized = self::sanitize($summary);

        if (is_array($sanitized) && isset($sanitized['per_sales_order']) && is_array($sanitized['per_sales_order'])) {
            foreach ($sanitized['per_sales_order'] as $index => $row) {
                if (! is_array($row) || ! isset($row['error']) || isset($row['error_code'])) {
                    continue;
                }

                // Older rows stored raw exception messages before the safe
                // error contract existed. Do not leak those historical
                // messages merely because the row predates the new columns.
                $sanitized['per_sales_order'][$index]['error'] = 'MRP could not evaluate this sales order because of an internal planning error.';
                $sanitized['per_sales_order'][$index]['error_code'] = 'mrp_internal_error';
                $sanitized['per_sales_order'][$index]['recovery_action'] = 'Review the run logs with the run number, correct the source data if needed, then rerun MRP.';
            }
        }

        return is_array($sanitized) ? $sanitized : [];
    }

    /** @param mixed $costSummary @return array<string, mixed>|null */
    public static function costSummary(mixed $costSummary): ?array
    {
        if ($costSummary === null) {
            return null;
        }

        $sanitized = self::sanitize($costSummary);

        return is_array($sanitized) ? $sanitized : [];
    }

    /** @param mixed $context @return array<string, mixed>|null */
    public static function generationContext(mixed $context): ?array
    {
        if (! is_array($context)) {
            return null;
        }

        $allowed = ['source', 'trigger', 'reason', 'actor_type'];
        $result = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $context)) {
                $result[$key] = $context[$key];
            }
        }
        foreach (['run_id', 'actor_id', 'source_id'] as $key) {
            if (array_key_exists($key, $context)) {
                $result[$key] = self::hashId($context[$key]);
            }
        }

        return $result;
    }

    /** @param mixed $value */
    private static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $result[$childKey] = self::sanitize($childValue, (string) $childKey);
            }

            return $result;
        }

        if ($key !== null && self::isIdentifierKey($key)) {
            return self::hashId($value);
        }

        return $value;
    }

    /** @param mixed $value @return mixed */
    private static function hashId(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return app('hashids')->encode((int) $value);
        }

        return $value;
    }

    private static function isIdentifierKey(string $key): bool
    {
        return in_array($key, [
            'item_id', 'product_id', 'sales_order_line_id', 'so_id',
            'plan_id', 'mrp_plan_id', 'work_order_id', 'run_id',
            'source_id', 'actor_id',
        ], true);
    }

    /** @param mixed $value @return array<int, array<string, mixed>> */
    private static function sanitizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $row) {
            $sanitized = self::sanitize($row);
            if (is_array($sanitized)) {
                $result[] = $sanitized;
            }
        }

        return $result;
    }
}
