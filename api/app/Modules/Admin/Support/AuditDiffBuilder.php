<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Common\Support\AuditFieldLabels;

/** Builds the one canonical field-diff shape used by every audit view. */
final class AuditDiffBuilder
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<int, array<string, mixed>>
     */
    public static function build(string $modelType, array $old, array $new): array
    {
        $old = self::publicValues($old);
        $new = self::publicValues($new);
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $rows = [];

        foreach ($keys as $key) {
            $hasOld = array_key_exists($key, $old);
            $hasNew = array_key_exists($key, $new);
            $meta = AuditFieldLabels::field($modelType, (string) $key);
            $label = $meta['label'] ?? self::humanize((string) $key);
            $type = $meta['type'] ?? 'text';

            // Never put encrypted values in an API response, even when a
            // legacy row predates HasAuditLog's redaction.
            if ($type === 'encrypted') {
                if ($hasOld && ! $hasNew) {
                    $rows[] = ['kind' => 'removed', 'key' => $key, 'label' => $label, 'type' => $type, 'old' => null];
                } elseif (! $hasOld && $hasNew) {
                    $rows[] = ['kind' => 'added', 'key' => $key, 'label' => $label, 'type' => $type, 'new' => null];
                } elseif ($old[$key] !== $new[$key]) {
                    $rows[] = ['kind' => 'changed', 'key' => $key, 'label' => $label, 'type' => $type];
                }
                continue;
            }

            if ($hasOld && ! $hasNew) {
                $rows[] = ['kind' => 'removed', 'key' => $key, 'label' => $label, 'type' => $type, 'old' => $old[$key]];
            } elseif (! $hasOld && $hasNew) {
                $rows[] = ['kind' => 'added', 'key' => $key, 'label' => $label, 'type' => $type, 'new' => $new[$key]];
            } elseif ($old[$key] !== $new[$key]) {
                $rows[] = ['kind' => 'changed', 'key' => $key, 'label' => $label, 'type' => $type, 'old' => $old[$key], 'new' => $new[$key]];
            }
        }

        return $rows;
    }

    /** Convert raw integer IDs nested in audit JSON to public HashIDs. */
    public static function publicValues(array $values): array
    {
        return self::transform($values);
    }

    private static function transform(mixed $value, ?string $parentKey = null): mixed
    {
        if (is_array($value)) {
            $mapped = [];
            foreach ($value as $key => $child) {
                $publicKey = is_string($parentKey) && self::isIdKey($parentKey) && is_numeric((string) $key)
                    ? app('hashids')->encode((int) $key)
                    : $key;
                $mapped[$publicKey] = self::transform($child, is_string($parentKey) ? $parentKey : (is_string($key) ? $key : null));
            }

            return $mapped;
        }

        return is_string($parentKey) && self::isIdKey($parentKey) && is_numeric((string) $value)
            ? app('hashids')->encode((int) $value)
            : $value;
    }

    private static function isIdKey(string $key): bool
    {
        return $key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_ids');
    }

    private static function humanize(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
