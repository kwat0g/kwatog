<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Delivery analytics. New — SupplyChain had no aggregate endpoint. */
final class SupplyChainWidgetAnalytics
{
    /**
     * Statuses that still owe a delivery. Loading sits between Scheduled and
     * InTransit, so omitting it would silently drop trucks being loaded from
     * both the overdue list and the schedule.
     */
    private const OPEN = [
        DeliveryStatus::Scheduled->value,
        DeliveryStatus::Loading->value,
        DeliveryStatus::InTransit->value,
    ];

    /** @return list<string> */
    public function handles(): array
    {
        return ['supply.overdue_deliveries', 'supply.delivery_schedule'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return match ($key) {
            'supply.overdue_deliveries' => $this->overdue(),
            'supply.delivery_schedule' => $this->schedule(),
            default => [],
        };
    }

    /**
     * Overdue deliveries, oldest first — the ones to chase today.
     *
     * @return array<string, mixed>
     */
    private function overdue(): array
    {
        $base = fn () => DB::table('deliveries')
            ->whereIn('status', self::OPEN)
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->whereNull('deleted_at');

        $rows = $base()
            ->select('delivery_number', 'status', 'scheduled_date')
            ->orderBy('scheduled_date')
            ->limit(10)
            ->get();

        return [
            'columns' => [
                ['key' => 'delivery_number', 'label' => 'Delivery', 'align' => 'left'],
                ['key' => 'scheduled_date', 'label' => 'Scheduled', 'align' => 'left'],
                ['key' => 'days_late', 'label' => 'Days late', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn ($r) => [
                'delivery_number' => (string) $r->delivery_number,
                'scheduled_date' => (string) $r->scheduled_date,
                // Carbon 3 returns a SIGNED float, so a past date gives -5.0.
                // Lateness is a magnitude — abs() before the int cast, or the
                // column reads "-5 days late".
                'days_late' => (int) abs(
                    Carbon::now()->startOfDay()->diffInDays(Carbon::parse($r->scheduled_date))
                ),
            ])->values()->all(),
            'total_count' => (int) $base()->count(),
        ];
    }

    /**
     * Deliveries due in the next 7 days, soonest first.
     *
     * @return array<string, mixed>
     */
    private function schedule(): array
    {
        $from = now()->toDateString();
        $to = now()->addDays(7)->toDateString();

        $base = fn () => DB::table('deliveries')
            ->whereIn('status', self::OPEN)
            ->whereBetween('scheduled_date', [$from, $to])
            ->whereNull('deleted_at');

        $rows = $base()
            ->select('delivery_number', 'status', 'scheduled_date')
            ->orderBy('scheduled_date')
            ->limit(10)
            ->get();

        return [
            'columns' => [
                ['key' => 'delivery_number', 'label' => 'Delivery', 'align' => 'left'],
                ['key' => 'scheduled_date', 'label' => 'Scheduled', 'align' => 'left'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left'],
            ],
            'rows' => $rows->map(fn ($r) => [
                'delivery_number' => (string) $r->delivery_number,
                'scheduled_date' => (string) $r->scheduled_date,
                'status' => (string) $r->status,
            ])->values()->all(),
            'total_count' => (int) $base()->count(),
        ];
    }
}
