<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/** CRM analytics: complaint mix. New — CRM had no aggregate endpoint. */
final class CrmWidgetAnalytics
{
    private const TONE = [
        'open' => 'danger',
        'investigating' => 'warning',
        'resolved' => 'success',
        'closed' => 'neutral',
    ];

    /** @return list<string> */
    public function handles(): array
    {
        return ['crm.open_complaints'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        if ($key !== 'crm.open_complaints') {
            return [];
        }

        $rows = DB::table('customer_complaints')
            ->selectRaw('status as label, COUNT(*) as value')
            ->groupBy('status')
            ->orderByDesc('value')
            ->get();

        return [
            'total' => (int) $rows->sum('value'),
            'segments' => $rows->map(fn ($r) => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
                'tone' => self::TONE[(string) $r->label] ?? 'neutral',
            ])->values()->all(),
        ];
    }
}
