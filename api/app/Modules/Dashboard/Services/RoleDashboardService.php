<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;

/**
 * Sprint 8 — Tasks 72 + 73. Thin facade — delegates to focused dashboard services.
 *
 * P4.1 refactor: logic has been extracted into:
 *   - PlantManagerDashboardService
 *   - HrDashboardService          (covers employee dashboard too)
 *   - PpcDashboardService         (covers accounting dashboard too)
 *   - PurchasingDashboardService
 *   - WarehouseDashboardService
 *   - QualityDashboardService
 *
 * This class is kept so that any external code that still type-hints
 * RoleDashboardService continues to work without change.
 */
class RoleDashboardService
{
    public function __construct(
        private readonly PlantManagerDashboardService $plantManagerService,
        private readonly HrDashboardService           $hrService,
        private readonly PpcDashboardService          $ppcService,
        private readonly PurchasingDashboardService   $purchasingService,
        private readonly WarehouseDashboardService    $warehouseService,
        private readonly QualityDashboardService      $qualityService,
    ) {}

    public function plantManager(User $user, string $range = 'week'): array
    {
        return $this->plantManagerService->plantManager($user, $range);
    }

    public function hr(User $user): array
    {
        return $this->hrService->hr($user);
    }

    public function ppc(User $user): array
    {
        return $this->ppcService->ppc($user);
    }

    public function accounting(User $user): array
    {
        return $this->ppcService->accounting($user);
    }

    public function employee(User $user): array
    {
        return $this->hrService->employee($user);
    }

    public function purchasing(User $user): array
    {
        return $this->purchasingService->purchasing($user);
    }

    public function warehouse(User $user): array
    {
        return $this->warehouseService->warehouse($user);
    }

    public function quality(User $user): array
    {
        return $this->qualityService->quality($user);
    }
}
