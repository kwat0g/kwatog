<?php

declare(strict_types=1);

namespace App\Common\Support;

use Illuminate\Support\Facades\DB;

/**
 * Cross-driver case-insensitive `LIKE` helper.
 *
 * Why this exists: PostgreSQL has the convenient `ILIKE` operator but
 * SQLite (used in `phpunit` tests) and MySQL do not. Hard-coding `ilike`
 * in queries breaks the test suite the moment a search filter is exercised
 * and quietly excludes any deployment that ever moves off Postgres.
 *
 * Usage:
 *   $q->where('name', SearchOperator::like(), '%foo%')
 *
 * For Postgres this returns the native `ilike` operator; on every other
 * driver it falls back to `like`, which is already case-insensitive on
 * SQLite by default and on MySQL when the column collation is *_ci
 * (the Laravel default).
 */
final class SearchOperator
{
    public static function like(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
