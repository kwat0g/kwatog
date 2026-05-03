<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-loads /api/v1/* routes from every app/Modules/<Module>/routes.php file.
 *
 * Each module is responsible for its own controllers, models, services,
 * requests, resources, jobs, enums, and routes — the modular monolith approach.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Module list — order matters only for IDE navigation; routing is independent.
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
    ];

    public function boot(): void
    {
        $this->registerRoutes();
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
