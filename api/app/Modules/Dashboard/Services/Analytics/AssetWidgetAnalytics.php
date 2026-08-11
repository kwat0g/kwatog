<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/** Asset analytics: register mix by category. New — Assets had no aggregate endpoint. */
final class AssetWidgetAnalytics
{
    /** @return list<string> */
    public function handles(): array
    {
        return ['assets.under_maintenance'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'assets.under_maintenance') {
            return [];
        }

        // Out-of-service assets grouped by category: "which KIND of asset is
        // down" is the actionable reading; a bare count is not.
        $rows = DB::table('assets')
            ->selectRaw('category as label, COUNT(*) as value')
            ->where('status', AssetStatus::UnderMaintenance->value)
            ->whereNull('deleted_at')
            ->groupBy('category')
            ->orderByDesc('value')
            ->limit(8)
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) ($r->label ?? 'Uncategorised'),
                'value' => (int) $r->value,
                'tone' => 'warning',
            ])->values()->all(),
        ];
    }
}
