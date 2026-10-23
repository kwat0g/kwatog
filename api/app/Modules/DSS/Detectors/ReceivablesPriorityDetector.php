<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use Illuminate\Support\Carbon;

/** D09 — prioritize customers past the first configured dunning tier. */
class ReceivablesPriorityDetector implements DetectorInterface
{
    public function __construct(private readonly SettingsService $settings) {}

    public function id(): string
    {
        return 'dss.receivables_priority';
    }

    public function domain(): string
    {
        return 'accounting';
    }

    public function ownerPermissions(): array
    {
        return ['accounting.invoices.collect'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        $tiers = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', $this->settings->requiredString('accounting.ar_dunning.tier_days_csv')),
        ), static fn (int $days): bool => $days > 0)));
        if ($tiers === []) {
            return new DetectorScanResult([], 0, false);
        }

        $firstTier = min($tiers);
        $highestTier = max($tiers);
        $limit = max(1, (int) ($context['limit'] ?? 200));
        $customers = Invoice::query()
            ->open()
            ->where('invoices.balance', '>', 0)
            ->whereNotNull('invoices.due_date')
            ->whereDate('invoices.due_date', '<=', today()->subDays($firstTier))
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select('customers.id as customer_id', 'customers.name as customer_name')
            ->selectRaw('COUNT(invoices.id) as overdue_invoice_count, SUM(invoices.balance) as open_balance, MIN(invoices.due_date) as oldest_due_date')
            ->groupBy('customers.id', 'customers.name')
            ->orderBy('oldest_due_date')
            ->limit($limit + 1)
            ->get();

        $complete = $customers->count() <= $limit;
        $emissions = $customers->take($limit)->map(function ($row) use ($firstTier, $highestTier): DetectorEmission {
            $oldestDueDate = Carbon::parse($row->oldest_due_date);
            $daysOverdue = today()->diffInDays($oldestDueDate, true);

            return new DetectorEmission(
                type: 'receivables_priority',
                severity: $daysOverdue >= $highestTier ? 'critical' : 'warning',
                dedupeKey: 'receivables:customer:'.$row->customer_id,
                rationale: [
                    'rule' => 'open invoices passed the first dunning tier',
                    'customer' => $row->customer_name,
                    'overdue_invoice_count' => (int) $row->overdue_invoice_count,
                    'open_balance' => (string) $row->open_balance,
                    'oldest_due_date' => (string) $row->oldest_due_date,
                    'days_overdue' => $daysOverdue,
                    'first_dunning_tier_days' => $firstTier,
                ],
                subjectType: 'customer',
                subjectId: (int) $row->customer_id,
                impactEstimate: (string) $row->open_balance,
                impactUnit: 'PHP',
                confidence: 1.0,
                action: ['route' => '/accounting/invoices', 'label' => 'Review overdue invoices →'],
                ownerPermissions: ['accounting.invoices.collect'],
                ownerRoleSlugs: ['finance_officer'],
                impactCurrency: 'PHP',
                impactMetric: 'Overdue receivable balance',
                businessDueAt: $oldestDueDate->copy()->addDays($firstTier),
            );
        })->all();

        return new DetectorScanResult($emissions, (int) $customers->sum('overdue_invoice_count'), $complete);
    }
}
