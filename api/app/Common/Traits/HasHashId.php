<?php

declare(strict_types=1);

namespace App\Common\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Encodes/decodes the integer primary key as a hash, exposes it as `hash_id`,
 * and resolves implicit route bindings using the hash.
 *
 * Apply to every model. Resources MUST emit `'id' => $this->hash_id`.
 *
 * @mixin Model
 */
trait HasHashId
{
    /**
     * Resolve route binding by hash_id (default) or by the explicit field.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        // Allow explicit field-based routing (e.g. {employee:slug})
        if ($field !== null) {
            return $this->where($field, $value)->firstOrFail();
        }

        // Numeric values are treated as raw integer IDs only in non-production
        // (useful in seeds/tests). Production always decodes through HashIDs.
        if (! app()->environment('production') && ctype_digit((string) $value)) {
            return $this->newQuery()->whereKey((int) $value)->firstOrFail();
        }

        $decoded = app('hashids')->decode((string) $value);
        if (empty($decoded)) {
            abort(404);
        }

        return $this->newQuery()->whereKey((int) $decoded[0])->firstOrFail();
    }

    /**
     * Accessor: $model->hash_id  →  encoded string.
     */
    public function getHashIdAttribute(): string
    {
        return app('hashids')->encode($this->getKey());
    }

    /**
     * Decode a hash to an integer ID, or fail.
     */
    public static function decodeHash(string $hash): int
    {
        $decoded = app('hashids')->decode($hash);
        abort_if(empty($decoded), 404);

        return (int) $decoded[0];
    }

    /**
     * Decode a hash without aborting (returns null on failure).
     */
    public static function tryDecodeHash(string $hash): ?int
    {
        $decoded = app('hashids')->decode($hash);
        return empty($decoded) ? null : (int) $decoded[0];
    }

    /**
     * Scope: ->whereHash($hash) — useful for filtering by hashed FK in requests.
     */
    public function scopeWhereHash(Builder $query, string $hash, string $column = 'id'): Builder
    {
        $id = self::tryDecodeHash($hash);
        return $query->where($column, $id ?? -1);
    }
}
