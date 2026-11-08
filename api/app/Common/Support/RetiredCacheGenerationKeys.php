<?php

declare(strict_types=1);

namespace App\Common\Support;

/** Extracts version tokens from versioned application cache-key segments. */
final class RetiredCacheGenerationKeys
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** @var list<string> */
    private const GENERATION_FAMILIES = [
        'auth:role_perms:',
        'badges.user.',
        'dash:an:',
        'dashboard:',
        'dss:params:',
        'finance_dashboard:',
        'gov_table:',
        'holidays:',
        'inv:dashboard:summary:',
        'payroll:progress:',
        'procurement_chain:',
        'settings:',
        'stmt:',
    ];

    /** @return list<string> */
    public static function fromCacheKey(string $key): array
    {
        $isGenerationFamily = false;
        foreach (self::GENERATION_FAMILIES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $isGenerationFamily = true;
                break;
            }
        }
        if (! $isGenerationFamily) {
            return [];
        }

        if (str_starts_with($key, 'holidays:')
            && preg_match('/^holidays:\d{4}:('.self::UUID.')$/i', $key, $holidayMatch) === 1) {
            return [strtolower($holidayMatch[1])];
        }

        // `g` is the role-permission generation and `s` is the badge snapshot's
        // inventory generation; some dashboard families put `v3:`/`v4:` before
        // the UUID without changing the underlying CacheGeneration marker.
        $pattern = '/(?:^|[.:])(?:v|g|s)(?:[0-9]+:)?('.self::UUID.')(?:\.('.self::UUID.'))?(?=$|[.:])/i';
        preg_match_all($pattern, $key, $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[] = strtolower($match[1]);
            if (isset($match[2]) && $match[2] !== '') {
                $tokens[] = strtolower($match[2]);
            }
        }

        return array_values(array_unique($tokens));
    }
}
