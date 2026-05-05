<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Common\Notifications\CriticalAlertEmail;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Task A2 — Smart Alert Engine.
 *
 * runAllChecks() iterates every monitored threshold and raises alerts. The
 * `raise()` method is idempotent: if an undismissed alert exists for the
 * same (type, entity_type, entity_id) within the last 24h, no duplicate
 * is created. This keeps the alert list bounded even when the engine runs
 * every 15 minutes.
 *
 * Critical alerts also fan out an email immediately (best-effort — failures
 * are logged but do not abort the run).
 */
class AlertEngineService
{
    private const DEDUP_WINDOW_HOURS = 24;
    private const OEE_THRESHOLD      = 0.75;
    private const OEE_DAYS           = 3;

    /**
     * Idempotent alert creation. Returns the existing record if a recent
     * undismissed match is found.
     */
    public function raise(
        AlertType $type,
        AlertSeverity $severity,
        string $title,
        string $message,
        ?Model $entity = null,
        array $metadata = [],
    ): Alert {
        $query = Alert::query()
            ->where('type', $type->value)
            ->where('is_dismissed', false)
            ->where('created_at', '>=', now()->subHours(self::DEDUP_WINDOW_HOURS));

        if ($entity) {
            $query->where('entity_type', $entity::class)
                  ->where('entity_id', $entity->getKey());
        } else {
            $query->whereNull('entity_id');
        }

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        $alert = Alert::create([
            'type'        => $type->value,
            'severity'    => $severity->value,
            'title'       => $title,
            'message'     => $message,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id'   => $entity?->getKey(),
            'metadata'    => $metadata,
        ]);

        if ($severity === AlertSeverity::Critical) {
            $this->emailCritical($alert);
        }

        return $alert;
    }

    public function dismiss(Alert $alert, User $user): Alert
    {
        $alert->update([
            'is_dismissed' => true,
            'dismissed_by' => $user->id,
            'dismissed_at' => now(),
        ]);
        return $alert->fresh();
    }

    public function markRead(Alert $alert): Alert
    {
        if (! $alert->is_read) {
            $alert->update(['is_read' => true]);
        }
        return $alert->fresh();
    }

    /**
     * @return array{raised:int,by_severity:array<string,int>,by_type:array<string,int>}
     */
    public function runAllChecks(): array
    {
        $stats = ['raised' => 0, 'by_severity' => [], 'by_type' => []];
        $before = Alert::count();

        $this->safe(fn () => $this->checkInventory(),  'inventory');
        $this->safe(fn () => $this->checkProduction(), 'production');
        $this->safe(fn () => $this->checkFinance(),    'finance');
        $this->safe(fn () => $this->checkQuality(),    'quality');

        $raised = max(0, Alert::count() - $before);
        $stats['raised'] = $raised;

        foreach (AlertSeverity::values() as $sev) {
            $stats['by_severity'][$sev] = Alert::where('severity', $sev)
                ->where('created_at', '>=', now()->subMinutes(15))->count();
        }

        return $stats;
    }

    private function safe(callable $fn, string $label): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("AlertEngine: {$label} check failed", ['error' => $e->getMessage()]);
        }
    }

    /* ─── Inventory checks ────────────────────────────────────────── */

    private function checkInventory(): void
    {
        // Sum stock per item across all locations.
        $rows = DB::table('stock_levels')
            ->select('item_id', DB::raw('SUM(quantity) as on_hand'))
            ->groupBy('item_id');

        $items = Item::query()
            ->where('is_active', true)
            ->leftJoinSub($rows, 'sl', 'sl.item_id', '=', 'items.id')
            ->select(
                'items.*',
                DB::raw('COALESCE(sl.on_hand, 0) as on_hand'),
            )
            ->get();

        foreach ($items as $item) {
            $onHand   = (float) ($item->on_hand ?? 0);
            $reorder  = (float) $item->reorder_point;
            $safety   = (float) $item->safety_stock;

            if ($safety > 0 && $onHand < $safety) {
                $this->raise(
                    AlertType::StockCritical,
                    AlertSeverity::Critical,
                    "Critical stock: {$item->code}",
                    "{$item->name} on hand is {$onHand} {$item->unit_of_measure}, below safety stock of {$safety}.",
                    $item,
                    ['on_hand' => $onHand, 'safety_stock' => $safety, 'reorder_point' => $reorder],
                );
                continue; // critical preempts low-stock for the same item
            }

            if ($reorder > 0 && $onHand < $reorder) {
                $this->raise(
                    AlertType::StockLow,
                    AlertSeverity::Warning,
                    "Low stock: {$item->code}",
                    "{$item->name} on hand is {$onHand} {$item->unit_of_measure}, below reorder point of {$reorder}.",
                    $item,
                    ['on_hand' => $onHand, 'reorder_point' => $reorder],
                );

                $supplierExists = ApprovedSupplier::where('item_id', $item->id)->exists();
                if (! $supplierExists) {
                    $this->raise(
                        AlertType::NoSupplier,
                        AlertSeverity::Warning,
                        "No supplier: {$item->code}",
                        "{$item->name} has no approved supplier and stock is below reorder point.",
                        $item,
                        ['on_hand' => $onHand, 'reorder_point' => $reorder],
                    );
                }
            }
        }
    }

    /* ─── Production checks ───────────────────────────────────────── */

    private function checkProduction(): void
    {
        // Machine breakdowns
        Machine::where('status', 'breakdown')->get()->each(function (Machine $m) {
            $this->raise(
                AlertType::MachineBreakdown,
                AlertSeverity::Critical,
                "Machine breakdown: {$m->machine_code}",
                "{$m->name} is reporting status 'breakdown'. Production halted on this machine.",
                $m,
                ['machine_code' => $m->machine_code],
            );
        });

        // Mold shot thresholds (80% / 95%)
        Mold::query()
            ->whereNotNull('max_shots_before_maintenance')
            ->where('max_shots_before_maintenance', '>', 0)
            ->get()
            ->each(function (Mold $mold) {
                $max = (int) $mold->max_shots_before_maintenance;
                $cur = (int) $mold->current_shot_count;
                $pct = $max > 0 ? ($cur / $max) : 0;

                if ($pct >= 0.95) {
                    $this->raise(
                        AlertType::MoldShotCritical,
                        AlertSeverity::Critical,
                        "Mold maintenance critical: {$mold->mold_code}",
                        "{$mold->name} is at ".round($pct * 100, 1)."% of its shot limit ({$cur}/{$max}). Immediate maintenance required.",
                        $mold,
                        ['shot_count' => $cur, 'max_shots' => $max, 'percent' => round($pct * 100, 2)],
                    );
                } elseif ($pct >= 0.80) {
                    $this->raise(
                        AlertType::MoldShotLimit,
                        AlertSeverity::Warning,
                        "Mold approaching shot limit: {$mold->mold_code}",
                        "{$mold->name} is at ".round($pct * 100, 1)."% of its shot limit ({$cur}/{$max}). Schedule preventive maintenance.",
                        $mold,
                        ['shot_count' => $cur, 'max_shots' => $max, 'percent' => round($pct * 100, 2)],
                    );
                }
            });

        // Work order overdue
        WorkOrder::query()
            ->whereIn('status', ['planned', 'confirmed', 'in_progress', 'in_production', 'paused'])
            ->whereNotNull('planned_end')
            ->where('planned_end', '<', now())
            ->get()
            ->each(function (WorkOrder $wo) {
                $hours = abs(now()->diffInHours($wo->planned_end));
                $this->raise(
                    AlertType::WoOverdue,
                    AlertSeverity::Warning,
                    "Work order overdue: {$wo->wo_number}",
                    "{$wo->wo_number} planned end was {$wo->planned_end?->toDateTimeString()} ({$hours}h overdue).",
                    $wo,
                    ['hours_overdue' => (int) $hours, 'status' => (string) ($wo->status?->value ?? $wo->status)],
                );
            });

        // OEE below 75% for 3+ consecutive days. Using a simple proxy:
        // for each machine, compute (good_count / max(1, good+reject)) over
        // the last 3 days from work_order_outputs. If < 0.75, raise.
        $cutoff = now()->subDays(self::OEE_DAYS)->toDateString();
        $rows = DB::table('work_order_outputs as wo')
            ->join('work_orders as w', 'w.id', '=', 'wo.work_order_id')
            ->whereNotNull('w.machine_id')
            ->where('wo.recorded_at', '>=', $cutoff)
            ->groupBy('w.machine_id')
            ->select(
                'w.machine_id',
                DB::raw('SUM(wo.good_count) as good'),
                DB::raw('SUM(wo.reject_count) as reject'),
            )
            ->get();

        foreach ($rows as $row) {
            $total = (int) ($row->good + $row->reject);
            if ($total < 100) continue; // not enough data
            $quality = $row->good / max(1, $total);
            if ($quality < self::OEE_THRESHOLD) {
                $machine = Machine::find($row->machine_id);
                if ($machine) {
                    $this->raise(
                        AlertType::OeeBelowThreshold,
                        AlertSeverity::Warning,
                        "OEE below threshold: {$machine->machine_code}",
                        "{$machine->name} quality rate is ".round($quality * 100, 1)."% over the last ".self::OEE_DAYS." days.",
                        $machine,
                        ['quality' => round($quality, 4), 'good' => (int) $row->good, 'reject' => (int) $row->reject],
                    );
                }
            }
        }
    }

    /* ─── Finance checks ──────────────────────────────────────────── */

    private function checkFinance(): void
    {
        $today = Carbon::today();

        // AR overdue 30 / 60
        DB::table('invoices')
            ->whereIn('status', ['unpaid', 'partial', 'finalized'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today->copy()->subDays(30))
            ->select('id', 'invoice_number', 'due_date', 'balance', 'customer_id')
            ->get()
            ->each(function ($row) use ($today) {
                $invoice = \App\Modules\Accounting\Models\Invoice::find($row->id);
                if (! $invoice) return;

                $daysOver = $today->diffInDays(Carbon::parse($row->due_date));
                if ($daysOver >= 60) {
                    $this->raise(
                        AlertType::ArOverdue60,
                        AlertSeverity::Critical,
                        "AR severely overdue: {$row->invoice_number}",
                        "Invoice {$row->invoice_number} is {$daysOver} days past due. Balance ₱".number_format((float) $row->balance, 2).".",
                        $invoice,
                        ['days_overdue' => $daysOver, 'balance' => (float) $row->balance],
                    );
                } else {
                    $this->raise(
                        AlertType::ArOverdue30,
                        AlertSeverity::Warning,
                        "AR overdue: {$row->invoice_number}",
                        "Invoice {$row->invoice_number} is {$daysOver} days past due. Balance ₱".number_format((float) $row->balance, 2).".",
                        $invoice,
                        ['days_overdue' => $daysOver, 'balance' => (float) $row->balance],
                    );
                }
            });

        // AP due in 3 days
        DB::table('bills')
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', $today->copy()->addDays(3))
            ->select('id', 'bill_number', 'due_date', 'balance', 'vendor_id')
            ->get()
            ->each(function ($row) {
                $bill = \App\Modules\Accounting\Models\Bill::find($row->id);
                if (! $bill) return;
                $this->raise(
                    AlertType::ApDueSoon,
                    AlertSeverity::Info,
                    "Bill due soon: {$row->bill_number}",
                    "Bill {$row->bill_number} is due in 3 days ({$row->due_date}). Balance ₱".number_format((float) $row->balance, 2).".",
                    $bill,
                    ['due_date' => $row->due_date, 'balance' => (float) $row->balance],
                );
            });
    }

    /* ─── Quality checks ──────────────────────────────────────────── */

    private function checkQuality(): void
    {
        // Daily scrap rate > 5% per product over last 24h
        $rows = DB::table('work_order_outputs as wo')
            ->join('work_orders as w', 'w.id', '=', 'wo.work_order_id')
            ->where('wo.recorded_at', '>=', now()->subDay())
            ->groupBy('w.product_id')
            ->select(
                'w.product_id',
                DB::raw('SUM(wo.good_count) as good'),
                DB::raw('SUM(wo.reject_count) as reject'),
            )
            ->get();

        foreach ($rows as $row) {
            $total = (int) ($row->good + $row->reject);
            if ($total < 100) continue;
            $scrap = $row->reject / max(1, $total);
            if ($scrap > 0.05) {
                $product = \App\Modules\MRP\Models\Product::find($row->product_id);
                if ($product) {
                    $this->raise(
                        AlertType::QcFailRateHigh,
                        AlertSeverity::Warning,
                        "High scrap rate: {$product->part_number}",
                        "{$product->name} scrap rate is ".round($scrap * 100, 2)."% over the last 24 hours ({$row->reject} rejected of {$total}).",
                        $product,
                        ['scrap_rate' => round($scrap, 4), 'good' => (int) $row->good, 'reject' => (int) $row->reject],
                    );
                }
            }
        }
    }

    /* ─── Email fanout ────────────────────────────────────────────── */

    private function emailCritical(Alert $alert): void
    {
        try {
            // Determine recipients by alert type → role.
            $roleSlugs = match ($alert->type) {
                AlertType::StockCritical, AlertType::StockLow, AlertType::NoSupplier
                    => ['warehouse_staff', 'purchasing_officer', 'ppc_head'],

                AlertType::MachineBreakdown, AlertType::MoldShotCritical, AlertType::MoldShotLimit
                    => ['production_manager', 'maintenance_tech'],

                AlertType::WoOverdue, AlertType::OeeBelowThreshold
                    => ['production_manager'],

                AlertType::ArOverdue30, AlertType::ArOverdue60, AlertType::ApDueSoon
                    => ['finance_officer'],

                AlertType::QcFailRateHigh
                    => ['qc_inspector', 'production_manager'],
            };

            $users = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roleSlugs))
                ->where('is_active', true)
                ->get();

            if ($users->isEmpty()) return;

            Notification::send($users, new CriticalAlertEmail($alert));
            $alert->update(['notified_email_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('AlertEngine: critical email failed', ['error' => $e->getMessage(), 'alert_id' => $alert->id]);
        }
    }
}
