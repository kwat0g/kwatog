<?php

declare(strict_types=1);

namespace App\Common\Concerns;

use App\Common\Support\HashIdFilter;

/**
 * Decode hash_id strings → raw integer IDs in request payloads before validation.
 *
 * The frontend always sends hash_id strings for foreign-key fields (per the URL ID
 * obfuscation rule). Backend FormRequest rules expect raw integer IDs (`integer, exists:...`).
 * This trait bridges the two by replacing each declared hash_id field in `$this->data`
 * with the decoded integer (or null when undecodable, so `exists` validation will fail
 * cleanly with a "selected ... is invalid" message).
 *
 * Usage in a FormRequest:
 *
 *     use App\Common\Concerns\ResolvesHashIds;
 *
 *     class StoreFooRequest extends FormRequest
 *     {
 *         use ResolvesHashIds;
 *
 *         protected function hashIdFields(): array
 *         {
 *             return [
 *                 'parent_id'   => \App\Modules\Inventory\Models\ItemCategory::class,
 *                 'category_id' => \App\Modules\Inventory\Models\ItemCategory::class,
 *                 'items.*.item_id' => \App\Modules\Inventory\Models\Item::class,
 *             ];
 *         }
 *     }
 *
 * Wildcards (`items.*.item_id`) are supported via Laravel's data_get / Arr::set semantics.
 */
trait ResolvesHashIds
{
    /**
     * Map of field path → model class (must use HasHashId).
     * Subclasses override this; default is empty (no decoding).
     *
     * @return array<string, class-string>
     */
    protected function hashIdFields(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        $fields = $this->hashIdFields();
        if (empty($fields)) return;

        $payload = $this->all();
        $changed = false;

        foreach ($fields as $path => $modelClass) {
            if (str_contains($path, '*')) {
                $changed = $this->decodeWildcardPath($payload, $path, $modelClass) || $changed;
            } else {
                $changed = $this->decodeScalarPath($payload, $path, $modelClass) || $changed;
            }
        }

        if ($changed) {
            $this->merge($payload);
        }
    }

    private function decodeScalarPath(array &$payload, string $path, string $modelClass): bool
    {
        $value = data_get($payload, $path);
        if ($value === null || $value === '') return false;

        $decoded = HashIdFilter::decode($value, $modelClass);
        // Replace even when decoded is null so the `integer`/`exists` rule fails predictably.
        \Illuminate\Support\Arr::set($payload, $path, $decoded);
        return true;
    }

    private function decodeWildcardPath(array &$payload, string $path, string $modelClass): bool
    {
        // Walk to the array segment, then iterate.
        [$prefix, $suffix] = explode('.*.', $path, 2);
        $list = data_get($payload, $prefix);
        if (! is_array($list)) return false;

        $changed = false;
        foreach ($list as $idx => $_row) {
            $changed = $this->decodeScalarPath($payload, "{$prefix}.{$idx}.{$suffix}", $modelClass) || $changed;
        }
        return $changed;
    }
}
