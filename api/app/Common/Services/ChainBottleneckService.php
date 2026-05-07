<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Series C — Task C5. Chain Bottleneck Detection.
 *
 * Each detector returns rows of entities stuck at the same chain step
 * longer than the configured threshold. Thresholds and audience targeting
 * live in `config/chain.php`.
 *
 * Why use direct DB queries (not Eloquent): the alert engine already
 * scans many entities every 15 minutes; bottleneck checks run hourly
 * against potentially-large tables. A `DB::table()` query with selective
 * columns avoids N+1 hydration overhead and keeps the scheduled command
 * cheap. Hash IDs are computed via the global helper for the API output
 * but not stored — bottleneck rows are transient.
 */
class ChainBottleneckService
{
    /**
     * Run every detector. Returns a map of detector key -> list of rows.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function detectAll(): array
    {
        $cfg = config('chain.bottlenecks', []);

        $out = [];
        foreach (array_keys($cfg) as $key) {
            $out[$key] = $this->detect($key);
        }
        return $out;
    }

    /**
     * Run a single detector. Unknown keys return [].
     *
     * @return array<int, array<string, mixed>>
     */
    public function detect(string $key): array
    {
        $cfg = config("chain.bottlenecks.{$key}");
        if (! is_array($cfg)) return [];

        $hours    = (int) ($cfg['hours'] ?? 24);
        $audience = (string) ($cfg['audience'] ?? 'system_admin');
        $label    = (string) ($cfg['label'] ?? $key);
        $cutoff   = Carbon::now()->subHours($hours);

        return match ($key) {
            'so_at_mrp_planned'           => $this->soAtMrpPlanned($cutoff, $key, $label, $audience),
            'wo_confirmed_unstarted'      => $this->woConfirmedUnstarted($cutoff, $key, $label, $audience),
            'inspection_outgoing_pending' => $this->inspectionOutgoingPending($cutoff, $key, $label, $audience),
            'delivery_scheduled_overdue'  => $this->deliveryScheduledOverdue($cutoff, $key, $label, $audience),
            'invoice_draft_overdue'       => $this->invoiceDraftOverdue($cutoff, $key, $label, $audience),
            'pr_pending_overdue'          => $this->prPendingOverdue($cutoff, $key, $label, $audience),
            'bill_unpaid_overdue'         => $this->billUnpaidOverdue($cutoff, $key, $label, $audience),
            default                       => [],
        };
    }

    // ─── Detectors ─────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function soAtMrpPlanned(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('sales_orders')
            ->select(['id', 'so_number', 'status', 'updated_at'])
            ->where('status', 'confirmed')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(50)
            ->get();
        return $this->mapRows($rows, 'sales_order', 'so_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function woConfirmedUnstarted(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('work_orders')
            ->select(['id', 'wo_number', 'status', 'updated_at'])
            ->where('status', 'confirmed')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(50)
            ->get();
        return $this->mapRows($rows, 'work_order', 'wo_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function inspectionOutgoingPending(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('inspections')
            ->select(['id', 'inspection_number', 'status', 'created_at as updated_at'])
            ->where('stage', 'outgoing')
            ->whereIn('status', ['draft', 'in_progress'])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit(50)
            ->get();
        return $this->mapRows($rows, 'inspection', 'inspection_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function deliveryScheduledOverdue(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('deliveries')
            ->select(['id', 'delivery_number', 'status', 'updated_at'])
            ->where('status', 'scheduled')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(50)
            ->get();
        return $this->mapRows($rows, 'delivery', 'delivery_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function invoiceDraftOverdue(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('invoices')
            ->select(['id', 'invoice_number', 'status', 'updated_at'])
            ->where('status', 'draft')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(50)
            ->get();
        return $this->mapRows($rows, 'invoice', 'invoice_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function prPendingOverdue(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('purchase_requests')
            ->select(['id', 'pr_number', 'status', 'updated_at'])
            ->where('status', 'pending')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(50)
            ->get();
        return $this->mapRows($rows, 'purchase_request', 'pr_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function billUnpaidOverdue(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        // For bills the "stuck since" reference is due_date, not updated_at —
        // the bill is overdue when due_date is more than $hours in the past.
        $rows = DB::table('bills')
            ->select(['id', 'bill_number', 'status', 'due_date as updated_at'])
            ->where('status', 'unpaid')
            ->where('due_date', '<=', $cutoff->toDateString())
            ->orderBy('due_date')
            ->limit(50)
            ->get();
        return $this->mapRows($rows, 'bill', 'bill_number', $key, $label, $audience);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Common row-mapper. Adds hash_id, hours_stuck, key/label/audience.
     *
     * @param  iterable<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRows(
        iterable $rows,
        string $entityType,
        string $docNumberField,
        string $key,
        string $label,
        string $audience,
    ): array {
        $out = [];
        $now = Carbon::now();
        foreach ($rows as $row) {
            $stuckSince = $this->parseTimestamp($row->updated_at ?? null);
            $hoursStuck = $stuckSince ? $stuckSince->diffInHours($now) : null;

            $out[] = [
                'key'         => $key,
                'label'       => $label,
                'audience'    => $audience,
                'entity_type' => $entityType,
                'entity_id'   => app('hashids')->encode((int) $row->id),
                'doc_number'  => (string) ($row->{$docNumberField} ?? ''),
                'status'      => (string) ($row->status ?? ''),
                'stuck_since' => $stuckSince?->toIso8601String(),
                'hours_stuck' => $hoursStuck,
            ];
        }
        return $out;
    }

    private function parseTimestamp(mixed $raw): ?Carbon
    {
        if ($raw === null) return null;
        try {
            return $raw instanceof Carbon ? $raw : Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
