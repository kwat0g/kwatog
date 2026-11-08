<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services\Statements;

use App\Common\Support\CacheGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Retires financial statement snapshots without letting an old in-flight fill win. */
final class FinancialStatementCacheGeneration
{
    private const VERSION_KEY = 'accounting:financial-statements:version';

    private const VERSION_TTL_SECONDS = 604800;

    public static function current(): string
    {
        return CacheGeneration::currentDefault(
            self::VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'accounting.financial_statements',
        );
    }

    /** Schedule generation rotation after the outermost source transaction commits. */
    public static function rotateAfterCommit(): ?bool
    {
        $version = (string) Str::uuid();
        $rotated = null;

        DB::afterCommit(static function () use ($version, &$rotated): void {
            $rotated = CacheGeneration::rotateDefault(
                self::VERSION_KEY,
                self::VERSION_TTL_SECONDS,
                'accounting.financial_statements',
                $version,
            );
        });

        return $rotated;
    }

    /** Retire report snapshots after a database restore has completed. */
    public static function invalidateForDatabaseRestore(): bool
    {
        return CacheGeneration::rotateDefault(
            self::VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'accounting.financial_statements.database_restore',
        );
    }
}
