<?php

declare(strict_types=1);

namespace App\Common\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Applies a "trashed" filter from request query params to an Eloquent query.
 *
 * Usage in service list() methods:
 *   $query = TrashedFilter::apply(Model::query(), $filters);
 */
class TrashedFilter
{
    public static function apply(Builder $query, array $filters): Builder
    {
        $trashed = $filters['trashed'] ?? null;
        if ($trashed === 'only') {
            $query->onlyTrashed();
        } elseif ($trashed === 'with') {
            $query->withTrashed();
        }
        return $query;
    }
}