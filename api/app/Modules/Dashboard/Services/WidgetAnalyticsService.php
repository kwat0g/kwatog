<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Services\Analytics\ApprovalsWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\AssetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\BudgetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CoreWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CrmWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\KpiWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\LoanWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\ReturnWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\SupplyChainWidgetAnalytics;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a widget key into a rich payload (breakdown / trend / table / gauge).
 *
 * Read-only by construction — no transaction, no writes. Scalars are NOT
 * handled here; DashboardWidgetDataService remains the scalar path, so this
 * service is purely additive and a widget with no provider keeps working.
 */
final class WidgetAnalyticsService
{
    public function __construct(
        private readonly CoreWidgetAnalytics $core,
        private readonly CrmWidgetAnalytics $crm,
        private readonly AssetWidgetAnalytics $assets,
        private readonly SupplyChainWidgetAnalytics $supplyChain,
        private readonly ReturnWidgetAnalytics $returns,
        private readonly BudgetWidgetAnalytics $budgets,
        private readonly LoanWidgetAnalytics $loans,
        private readonly KpiWidgetAnalytics $kpis,
        private readonly ApprovalsWidgetAnalytics $approvals,
    ) {}

    /**
     * The rich payload for $key, or [] when nothing handles it — the caller
     * then renders the scalar. Returning [] rather than throwing is what keeps
     * one broken domain from blanking every tile on the page.
     *
     * @return array<string, mixed>
     */
    public function payload(string $key, RenderKind $kind, User $user): array
    {
        if ($kind === RenderKind::Scalar) {
            return [];
        }

        foreach ($this->providers() as $provider) {
            if (! in_array($key, $provider->handles(), true)) {
                continue;
            }

            try {
                return $provider->payload($key, $user);
            } catch (Throwable $e) {
                Log::warning('dashboard widget analytics failed', [
                    'widget' => $key,
                    'kind' => $kind->value,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        }

        return [];
    }

    /**
     * @return list<CoreWidgetAnalytics|CrmWidgetAnalytics|AssetWidgetAnalytics|SupplyChainWidgetAnalytics|ReturnWidgetAnalytics|BudgetWidgetAnalytics|LoanWidgetAnalytics|KpiWidgetAnalytics|ApprovalsWidgetAnalytics>
     */
    private function providers(): array
    {
        return [
            $this->core,
            $this->crm,
            $this->assets,
            $this->supplyChain,
            $this->returns,
            $this->budgets,
            $this->loans,
            $this->kpis,
            $this->approvals,
        ];
    }
}
