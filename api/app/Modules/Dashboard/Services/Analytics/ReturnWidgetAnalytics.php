<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** RMA analytics. New — Return Management had no aggregate endpoint. */
final class ReturnWidgetAnalytics
{
    private const TONE = [
        'draft' => 'neutral',
        'pending_approval' => 'warning',
        'approved' => 'success',
        'received' => 'success',
        'inspected' => 'neutral',
    ];

    /** @return list<string> */
    public function handles(): array
    {
        return ['rma.open_returns', 'rma.pending_approval'];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return match ($key) {
            'rma.open_returns' => $this->statusMix(),
            'rma.pending_approval' => $this->approvalQueue(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function statusMix(): array
    {
        $rows = DB::table('return_requests')
            ->selectRaw('status as label, COUNT(*) as value')
            ->whereNotIn('status', [
                ReturnRequestStatus::Completed->value,
                ReturnRequestStatus::Rejected->value,
                ReturnRequestStatus::Cancelled->value,
            ])
            ->whereNull('deleted_at')
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

    /**
     * Returns waiting on a decision, oldest first — an approver's worklist.
     *
     * @return array<string, mixed>
     */
    private function approvalQueue(): array
    {
        $base = fn () => DB::table('return_requests')
            ->where('status', ReturnRequestStatus::PendingApproval->value)
            ->whereNull('deleted_at');

        $rows = $base()
            ->select('rma_number', 'type', 'return_date')
            ->orderBy('return_date')
            ->limit(10)
            ->get();

        return [
            'columns' => [
                ['key' => 'rma_number', 'label' => 'RMA', 'align' => 'left'],
                ['key' => 'type', 'label' => 'Type', 'align' => 'left'],
                ['key' => 'waiting_days', 'label' => 'Waiting', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn ($r) => [
                'rma_number' => (string) $r->rma_number,
                'type' => (string) $r->type,
                // Signed under Carbon 3 — abs() so a wait reads positive.
                'waiting_days' => $r->return_date === null
                    ? null
                    : (int) abs(
                        Carbon::now()->startOfDay()->diffInDays(Carbon::parse($r->return_date))
                    ),
            ])->values()->all(),
            'total_count' => (int) $base()->count(),
        ];
    }
}
