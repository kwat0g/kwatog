<?php

declare(strict_types=1);

namespace App\Common\Support;

use Illuminate\Support\Facades\DB;

/**
 * Cross-driver case-insensitive `LIKE` helper, plus pattern builders.
 *
 * Why this exists: PostgreSQL has the convenient `ILIKE` operator but
 * SQLite (used in `phpunit` tests) and MySQL do not. Hard-coding `ilike`
 * in queries breaks the test suite the moment a search filter is exercised
 * and quietly excludes any deployment that ever moves off Postgres.
 *
 * Usage:
 *   $q->where('name', SearchOperator::like(), SearchOperator::contains($input))
 *
 * For Postgres this returns the native `ilike` operator; on every other
 * driver it falls back to `like`, which is already case-insensitive on
 * SQLite by default and on MySQL when the column collation is *_ci
 * (the Laravel default).
 *
 * ## Wildcards are the caller's, never the user's
 *
 * `contains()`/`startsWith()` ESCAPE `%` and `_` before wrapping the value.
 * Interpolating raw input into `"%{$input}%"` — which is what every call site
 * used to do — hands the user the wildcard vocabulary: a single `%` matches
 * every row in the table, and `_` matches any character, so `a_c` silently
 * matches far more than the literal string typed. That is not a SQL-injection
 * hole (values are still bound) but it IS an unbounded-scan and
 * wrong-results hole. There is no documented wildcard syntax for global
 * search: input is matched literally as a substring.
 */
final class SearchOperator
{
    public static function like(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Neutralise `LIKE`/`ILIKE` metacharacters so the value matches literally.
     *
     * Postgres and MySQL both treat backslash as the default escape character
     * for `LIKE`, so `\%` is a literal percent sign with no `ESCAPE` clause
     * needed. SQLite has NO default escape character — a backslash there is
     * just a backslash — so escaping would inject literal backslashes into the
     * pattern and break the match. SQLite is only ever a fallback driver here
     * (both the app and `phpunit` run on Postgres), so it keeps the old,
     * unescaped behaviour rather than a subtly wrong one.
     */
    public static function escape(string $value): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $value;
        }

        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** `%value%` — substring match, wildcards in $value escaped. */
    public static function contains(string $value): string
    {
        return '%'.self::escape(trim($value)).'%';
    }

    /** `value%` — prefix match, wildcards in $value escaped. */
    public static function startsWith(string $value): string
    {
        return self::escape(trim($value)).'%';
    }

    /** `value` — exact (case-insensitive) match, wildcards in $value escaped. */
    public static function exact(string $value): string
    {
        return self::escape(trim($value));
    }
}
