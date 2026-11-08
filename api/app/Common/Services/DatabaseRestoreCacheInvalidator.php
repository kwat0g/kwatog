<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Modules\Accounting\Services\Statements\FinancialStatementCacheGeneration;
use App\Modules\Attendance\Services\HolidayCacheGeneration;
use App\Modules\Dashboard\Services\Analytics\AnalyticsCache;
use App\Modules\Dashboard\Services\BadgeService;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\Inventory\Services\InventoryDashboardCache;
use App\Modules\Auth\Services\RolePermissionCacheGeneration;
use App\Modules\Payroll\Services\GovernmentContributionTableService;
use App\Modules\Payroll\Services\PayrollProgressTracker;
use App\Modules\Production\Services\ProductionDashboardService;
use App\Modules\Purchasing\Services\ProcurementChainCache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Retires application cache generations whose source rows change on restore. */
final class DatabaseRestoreCacheInvalidator
{
    /** @return array<string, bool> Cache owner to generation rotation result. */
    public function invalidate(): array
    {
        $owners = [
            'dashboard_snapshots' => static fn (): bool => DashboardCacheGeneration::invalidateForDatabaseRestore(),
            'badges' => static fn (): bool => BadgeService::invalidateForDatabaseRestore(),
            'financial_statements' => static fn (): bool => FinancialStatementCacheGeneration::invalidateForDatabaseRestore(),
            'procurement_chain' => fn (): bool => app(ProcurementChainCache::class)->invalidateAll(),
            'inventory_dashboard' => fn (): bool => app(InventoryDashboardCache::class)->invalidateForDatabaseRestore(),
            'production_dashboard' => static fn (): bool => ProductionDashboardService::invalidateForDatabaseRestore(),
            'analytics' => fn (): bool => app(AnalyticsCache::class)->invalidateForDatabaseRestore(),
            'settings' => fn (): bool => app(SettingsService::class)->invalidateForDatabaseRestore(),
            'attendance_holidays' => fn (): bool => app(HolidayCacheGeneration::class)->invalidateForDatabaseRestore(),
            'government_contribution_tables' => fn (): bool => app(GovernmentContributionTableService::class)->invalidateForDatabaseRestore(),
            'dss_parameters' => fn (): bool => app(DssParameterService::class)->invalidateForDatabaseRestore(),
            'payroll_progress' => fn (): bool => app(PayrollProgressTracker::class)->invalidateForDatabaseRestore(),
            'role_permissions' => fn (): bool => app(RolePermissionCacheGeneration::class)->invalidateForDatabaseRestore(),
        ];

        $results = [];
        foreach ($owners as $owner => $invalidate) {
            try {
                $results[$owner] = $invalidate();
            } catch (Throwable $exception) {
                $results[$owner] = false;
                Log::critical('Cache owner invalidation threw after database restore.', [
                    'owner' => $owner,
                    'exception' => $exception::class,
                ]);
            }

            if (! $results[$owner]) {
                Log::critical('Cache owner generation was not retired after database restore.', [
                    'owner' => $owner,
                ]);
            }
        }

        return $results;
    }
}
