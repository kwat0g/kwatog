<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\PriceRequestStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** CRM and Sales dashboard aggregates, kept to the read permissions on their source lists. */
final class CrmWidgetAnalytics
{
    private const TONE = [
        'open' => 'danger',
        'investigating' => 'warning',
        'resolved' => 'success',
        'closed' => 'neutral',
    ];

    private const ORDER_TONE = [
        'draft' => 'neutral',
        'awaiting_confirmation' => 'warning',
        'delivery_negotiation' => 'warning',
        'confirmed' => 'info',
        'in_production' => 'info',
        'partially_delivered' => 'warning',
        'delivered' => 'success',
        'invoiced' => 'success',
    ];

    private const PRICE_REQUEST_TONE = [
        'requested' => 'warning',
        'pending_finance' => 'info',
        'offered' => 'success',
    ];

    /** @return list<string> */
    public function handles(): array
    {
        return ['crm.open_complaints', 'sales.open_orders', 'sales.price_requests', 'sales.order_intake'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return match ($key) {
            'crm.open_complaints' => $this->statusBreakdown(
                'customer_complaints',
                [],
                self::TONE,
            ),
            'sales.open_orders' => $this->salesOrderPipeline(),
            'sales.price_requests' => $this->priceRequestQueue(),
            'sales.order_intake' => $this->salesOrderIntake(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function statusBreakdown(string $table, array $statuses, array $tones): array
    {
        $query = DB::table($table)
            ->selectRaw('status as label, COUNT(*) as value')
            ->groupBy('status')
            ->orderByDesc('value');

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $rows = $query->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($row) => [
                'label' => $this->statusLabel((string) $row->label),
                'value' => (int) $row->value,
                'tone' => $tones[(string) $row->label] ?? 'neutral',
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function salesOrderPipeline(): array
    {
        $rows = DB::table('sales_orders')
            ->selectRaw('status as label, COUNT(*) as value')
            ->whereNotIn('status', SalesOrderStatus::terminalForPipeline())
            ->groupBy('status')
            ->orderByDesc('value')
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($row) => [
                'label' => SalesOrderStatus::tryFrom((string) $row->label)?->label() ?? (string) $row->label,
                'value' => (int) $row->value,
                'tone' => self::ORDER_TONE[(string) $row->label] ?? 'neutral',
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function priceRequestQueue(): array
    {
        return $this->statusBreakdown(
            'price_requests',
            array_map(static fn (PriceRequestStatus $status): string => $status->value, [
                PriceRequestStatus::Requested,
                PriceRequestStatus::PendingFinance,
                PriceRequestStatus::Offered,
            ]),
            self::PRICE_REQUEST_TONE,
        );
    }

    /** @return array<string, mixed> */
    private function salesOrderIntake(): array
    {
        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $start = $currentMonth->subMonths(11);
        $end = $currentMonth->addMonth();
        $counts = DB::table('sales_orders')
            ->selectRaw("TO_CHAR(DATE_TRUNC('month', date), 'YYYY-MM') as month, COUNT(*) as value")
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $end->toDateString())
            ->groupByRaw("DATE_TRUNC('month', date)")
            ->pluck('value', 'month');

        $points = [];
        for ($month = $start; $month < $end; $month = $month->addMonth()) {
            $period = $month->format('Y-m');
            $points[] = [
                'label' => $month->format('M y'),
                'value' => (int) ($counts[$period] ?? 0),
            ];
        }

        return ['points' => $points, 'delta' => null, 'kind' => 'count'];
    }

    private function statusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}
