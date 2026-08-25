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

    private static function humanize(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
