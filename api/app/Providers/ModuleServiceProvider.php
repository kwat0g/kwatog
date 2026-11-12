<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-loads module views and /api/v1/* routes from app/Modules/<Module>.
 *
 * Each module is responsible for its own controllers, models, services,
 * requests, resources, jobs, enums, and routes - the modular monolith approach.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Module list - order matters only for IDE navigation; routing is independent.
     *
     * @var array<int, string>
     */
    private const MODULES = [
        'Auth',
        'Admin',
        'HR',
        'Attendance',
        'Leave',
        'Payroll',
        'Loans',
        'Accounting',
        'Inventory',
        'Purchasing',
        'SupplyChain',
        'Production',
        'MRP',
        'CRM',
        'Quality',
        'Maintenance',
        'Assets',
        'Dashboard',
        'B2B',
        'Forecasting',
        'ReturnManagement',
        'Landing',
        'DSS',
    ];

    public function boot(): void
    {
        $this->registerViews();
        $this->registerRoutes();
    }

    private function registerViews(): void
    {
        foreach (self::MODULES as $module) {
            $path = app_path("Modules/{$module}/resources/views");
            if (is_dir($path)) {
                $this->loadViewsFrom($path, strtolower($module));
            }
        }
    }

    private function registerRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(function (): void {
                foreach (self::MODULES as $module) {
                    $path = app_path("Modules/{$module}/routes.php");
                    if (is_file($path)) {
                        require $path;
                    }
                }
            });
    }
}
