<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Services;

use App\Common\Services\DatabaseRestoreCacheInvalidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DatabaseRestoreCacheInvalidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_restore_rotates_each_registered_data_cache_owner(): void
    {
        $keys = [
            'dashboard:cache:version',
            'badges.version',
            'accounting:financial-statements:version',
            'procurement_chain:procurement_chain_pr:version',
            'procurement_chain:procurement_chain_po:version',
            'procurement_chain:procurement_chain_grn:version',
            'procurement_chain:procurement_chain_bills:version',
            'procurement_chain:procurement_chain_three_way:version',
            'inv:dashboard:summary.version',
            'dashboard:production:version',
            'dash:an:ver:'.config('app.env', 'testing').':*',
            'settings:cache_namespace_version',
            'holidays:cache_version',
            'gov_table:sss:ver',
            'gov_table:philhealth:ver',
            'gov_table:pagibig:ver',
            'gov_table:bir:ver',
            'dss:params:restore_version',
            'payroll:progress:restore_version',
            'auth:role_perms:generation',
        ];
        foreach ($keys as $key) {
            Cache::put($key, 'before-restore', 3600);
        }

        $results = app(DatabaseRestoreCacheInvalidator::class)->invalidate();

        self::assertSame([
            'dashboard_snapshots' => true,
            'badges' => true,
            'financial_statements' => true,
            'procurement_chain' => true,
            'inventory_dashboard' => true,
            'production_dashboard' => true,
            'analytics' => true,
            'settings' => true,
            'attendance_holidays' => true,
            'government_contribution_tables' => true,
            'dss_parameters' => true,
            'payroll_progress' => true,
            'role_permissions' => true,
        ], $results);

        foreach ($keys as $key) {
            self::assertNotSame('before-restore', Cache::get($key), "Cache generation [{$key}] was not rotated.");
        }
    }
}
