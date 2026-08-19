<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Common\Notifications\CriticalAlertEmail;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Enums\WorkOrderStatus;
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
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SchedulerExecutionLedger $scheduler,
    ) {}

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
            ->where('created_at', '>=', now()->subHours($this->positiveIntSetting('alerts.dedup_window_hours')));

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
            'type' => $type->value,
            'severity' => $severity->value,
            'title' => $title,
            'message' => $message,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'metadata' => $metadata,
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
     * @return array{raised:int,by_severity:array<string,int>,by_type:array<string,int>,failed:array<int,string>}
     */
    public function runAllChecks(): array
    {
        $stats = ['raised' => 0, 'by_severity' => [], 'by_type' => [], 'failed' => []];
        $before = Alert::count();

        foreach ([
            'inventory' => fn () => $this->checkInventory(),
            'production' => fn () => $this->checkProduction(),
            'finance' => fn () => $this->checkFinance(),
            'quality' => fn () => $this->checkQuality(),
            'scheduler' => fn () => $this->checkScheduler(),
        ] as $label => $check) {
            $failure = $this->safe($check, $label);
            if ($failure !== null) {
                $stats['failed'][] = $failure;
            }
        }

        $raised = max(0, Alert::count() - $before);
        $stats['raised'] = $raised;

        foreach (AlertSeverity::values() as $sev) {
            $stats['by_severity'][$sev] = Alert::where('severity', $sev)
                ->where('created_at', '>=', now()->subMinutes(15))->count();
        }

        return $stats;
    }

    private function safe(callable $fn, string $label): ?string
    {
        try {
            $fn();
            return null;
        } catch (\Throwable $e) {
            Log::warning("AlertEngine: {$label} check failed", ['error' => $e->getMessage()]);
            return $label;
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
            $onHand = (float) ($item->on_hand ?? 0);
            $reorder = (float) $item->reorder_point;
            $safety = (float) $item->safety_stock;

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
        $moldWarningRatio = $this->ratioSetting('alerts.mold.warning_ratio');
        $moldCriticalRatio = $this->ratioSetting('alerts.mold.critical_ratio');
        if ($moldWarningRatio >= $moldCriticalRatio) {
            throw new \App\Common\Exceptions\BusinessRuleException('Mold warning ratio must be lower than its critical ratio.');
        }
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

        // Mold shot thresholds from the configured warning and critical ratios.
        Mold::query()
            ->whereNotNull('max_shots_before_maintenance')
            ->where('max_shots_before_maintenance', '>', 0)
            ->get()
            ->each(function (Mold $mold) use ($moldWarningRatio, $moldCriticalRatio) {
                $max = (int) $mold->max_shots_before_maintenance;
                $cur = (int) $mold->current_shot_count;
                $pct = $max > 0 ? ($cur / $max) : 0;

                if ($pct >= $moldCriticalRatio) {
                    $this->raise(
                        AlertType::MoldShotCritical,
                        AlertSeverity::Critical,
                        "Mold maintenance critical: {$mold->mold_code}",
                        "{$mold->name} is at ".round($pct * 100, 1)."% of its shot limit ({$cur}/{$max}). Immediate maintenance required.",
                        $mold,
                        ['shot_count' => $cur, 'max_shots' => $max, 'percent' => round($pct * 100, 2)],
                    );
                } elseif ($pct >= $moldWarningRatio) {
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
            ->whereIn('status', [
                WorkOrderStatus::Planned->value,
                WorkOrderStatus::Confirmed->value,
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::Paused->value,
            ])
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

        // OEE below the configured quality threshold over the configured lookback window. Using a simple proxy:
        // for each machine, compute (good_count / max(1, good+reject)) over
        // the configured lookback window from work_order_outputs. If below the configured threshold, raise.
        $oeeDays = $this->positiveIntSetting('alerts.oee.lookback_days');
        $minimumOutput = $this->positiveIntSetting('alerts.oee.minimum_output_count');
        $oeeThreshold = $this->ratioSetting('alerts.oee.quality_rate_threshold');
        $cutoff = now()->subDays($oeeDays)->toDateString();
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
            if ($total < $minimumOutput) {
                continue;
            } // not enough data
            $quality = $row->good / max(1, $total);
            if ($quality < $oeeThreshold) {
                $machine = Machine::find($row->machine_id);
                if ($machine) {
                    $this->raise(
                        AlertType::OeeBelowThreshold,
                        AlertSeverity::Warning,
                        "OEE below threshold: {$machine->machine_code}",
                        "{$machine->name} quality rate is ".round($quality * 100, 1)."% over the last {$oeeDays} days.",
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
        $warningDays = $this->positiveIntSetting('alerts.ar.warning_overdue_days');
        $criticalDays = $this->positiveIntSetting('alerts.ar.critical_overdue_days');
        $apDueSoonDays = $this->nonNegativeIntSetting('alerts.ap.due_soon_days');
        if ($warningDays >= $criticalDays) {
            throw new \App\Common\Exceptions\BusinessRuleException('AR warning days must be lower than AR critical days.');
        }

        // AR warning / critical overdue bands from configured day thresholds.
        DB::table('invoices')
            ->whereIn('status', [
                InvoiceStatus::Partial->value,
                InvoiceStatus::Finalized->value,
            ])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today->copy()->subDays($warningDays))
            ->select('id', 'invoice_number', 'due_date', 'balance', 'customer_id')
            ->get()
            ->each(function ($row) use ($today, $criticalDays) {
                $invoice = Invoice::find($row->id);
                if (! $invoice) {
                    return;
                }

                // Receiver-earlier, argument-later. Carbon 3 made diffIn* SIGNED,
                // and this read $today->diffInDays($dueDate) — the other way
                // round — so an overdue invoice yielded a NEGATIVE count. The
                // query above already filters due_date < today - warningDays, so
                // every row reaching here is overdue and `-75 >= 60` was always
                // false: the critical band could never be reached, and the
                // negative number was rendered into the message and stored in
                // days_overdue. Cast because Carbon 3 returns a float.
                $daysOver = (int) Carbon::parse($row->due_date)->diffInDays($today, true);
                if ($daysOver >= $criticalDays) {
                    $this->raise(
                        AlertType::ArOverdue60,
                        AlertSeverity::Critical,
                        "AR severely overdue: {$row->invoice_number}",
                        "Invoice {$row->invoice_number} is {$daysOver} days past due. Balance ".app(CurrencyDisplayService::class)->format($row->balance).'.',
                        $invoice,
                        ['days_overdue' => $daysOver, 'balance' => (float) $row->balance],
                    );
                } else {
                    $this->raise(
                        AlertType::ArOverdue30,
                        AlertSeverity::Warning,
                        "AR overdue: {$row->invoice_number}",
                        "Invoice {$row->invoice_number} is {$daysOver} days past due. Balance ".app(CurrencyDisplayService::class)->format($row->balance).'.',
                        $invoice,
                        ['days_overdue' => $daysOver, 'balance' => (float) $row->balance],
                    );
                }
            });

        // AP due-soon threshold from configured days.
        DB::table('bills')
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])
            ->whereDate('due_date', $today->copy()->addDays($apDueSoonDays))
            ->select('id', 'bill_number', 'due_date', 'balance', 'vendor_id')
            ->get()
            ->each(function ($row) use ($apDueSoonDays) {
                $bill = Bill::find($row->id);
                if (! $bill) {
                    return;
                }
                $this->raise(
                    AlertType::ApDueSoon,
                    AlertSeverity::Info,
                    "Bill due soon: {$row->bill_number}",
                    "Bill {$row->bill_number} is due in {$apDueSoonDays} days ({$row->due_date}). Balance ".app(CurrencyDisplayService::class)->format($row->balance).'.',
                    $bill,
                    ['due_date' => $row->due_date, 'balance' => (float) $row->balance],
                );
            });
    }

    /* ─── Quality checks ──────────────────────────────────────────── */

    private function checkQuality(): void
    {
        $lookbackHours = $this->positiveIntSetting('alerts.quality.lookback_hours');
        $minimumOutput = $this->positiveIntSetting('alerts.quality.minimum_output_count');
        $scrapThreshold = $this->ratioSetting('alerts.quality.scrap_rate_threshold');
        // Daily scrap rate > 5% per product over last 24h
        $rows = DB::table('work_order_outputs as wo')
            ->join('work_orders as w', 'w.id', '=', 'wo.work_order_id')
            ->where('wo.recorded_at', '>=', now()->subHours($lookbackHours))
            ->groupBy('w.product_id')
            ->select(
                'w.product_id',
                DB::raw('SUM(wo.good_count) as good'),
                DB::raw('SUM(wo.reject_count) as reject'),
            )
            ->get();

        foreach ($rows as $row) {
            $total = (int) ($row->good + $row->reject);
            if ($total < $minimumOutput) {
                continue;
            }
            $scrap = $row->reject / max(1, $total);
            if ($scrap > $scrapThreshold) {
                $product = Product::find($row->product_id);
                if ($product) {
                    $this->raise(
                        AlertType::QcFailRateHigh,
                        AlertSeverity::Warning,
                        "High scrap rate: {$product->part_number}",
                        "{$product->name} scrap rate is ".round($scrap * 100, 2)."% over the last {$lookbackHours} hours ({$row->reject} rejected of {$total}).",
                        $product,
                        ['scrap_rate' => round($scrap, 4), 'good' => (int) $row->good, 'reject' => (int) $row->reject],
                    );
                }
            }
        }
    }

    /* ─── Scheduler checks ────────────────────────────────────────── */

    /**
     * `api/routes/console.php` registers 42 scheduled entries — MRP planning,
     * payroll period creation, NCR escalation, alert dispatch, backups. If the
     * scheduler stalls they all stop at once, and until this check existed
     * nothing said so: `SchedulerExecutionLedger::health()` computed the
     * evidence and no caller consumed it.
     *
     * THIS IS NOT A DEAD-MAN SWITCH, and must not be described as one. The
     * thing that raises this alert is itself scheduled — `runAllChecks()` is
     * driven by `alerts:run` every 15 minutes — so a completely dead scheduler
     * raises nothing at all, and is caught by this check only once it comes
     * back and reads its own ledger. What it does catch, while the scheduler
     * is still running, is a STALLED or PARTIALLY FAILING one: a tick still
     * `running` past the threshold, a tick that last finished longer ago than
     * the threshold, a gap between consecutive ticks, and individual tasks
     * stuck or failing.
     *
     * Coverage for a fully dead scheduler has to come from outside the
     * process, and already does: `docker-compose.prod.yml`'s `scheduler`
     * service runs `scheduler:health --stale-minutes=15` as its Docker
     * healthcheck every 60s, over the same ledger. That control surfaces an
     * outage to whoever watches container health; this one records it inside
     * the application, where an operator will actually see it. They are
     * complements, not substitutes, and neither makes the other redundant.
     *
     * `raise()` de-duplicates on (type, null entity) inside
     * `alerts.dedup_window_hours`, so the first alert in a window keeps its
     * original message even if later issues differ. That is deliberate — one
     * standing alert per outage — but it means the message is the symptom at
     * first detection, not a running log.
     *
     * The title is deliberately generic, and that is a correction rather than
     * laziness. `health()` reports unhealthy when the latest run of ANY of the
     * 42 tasks failed, even while ticks are perfectly on time — so an earlier
     * title of "Scheduler is not running on schedule" told the operator
     * something false every time a single `db:backup` failed on a healthy
     * scheduler. The cost was not cosmetic: `$latestByTask` keeps a failed
     * latest run until that task next succeeds, and `scheduler:prune-ledger`
     * retains it for 90 days, so a monthly task that failed once holds a
     * standing Critical alert re-raised every 24 hours — under a title
     * describing a different fault. Naming the specific arm would mean
     * re-deriving which one fired, and this check deliberately does not
     * re-derive `health()`'s logic; the discrimination therefore lives where
     * it is already exact — the ledger's own `issues` strings in the message,
     * and the per-arm counts in `metadata`.
     */
    private function checkScheduler(): void
    {
        $staleMinutes = $this->positiveIntSetting('alerts.scheduler.stale_minutes');
        $health = $this->scheduler->health($staleMinutes);

        if ($health['healthy']) {
            return;
        }

        $issues = $health['issues'];
        $latestTick = $health['latest_tick'];

        $this->raise(
            AlertType::SchedulerStale,
            AlertSeverity::Critical,
            'Scheduler health is degraded',
            sprintf(
                'The scheduler execution ledger reports %d issue(s) against a %d-minute staleness threshold: %s',
                count($issues),
                $staleMinutes,
                implode(' ', $issues),
            ),
            null,
            [
                'stale_minutes' => $staleMinutes,
                'issues' => $issues,
                'latest_tick_status' => $latestTick?->status,
                'latest_tick_started_at' => $latestTick?->started_at?->toDateTimeString(),
                'latest_tick_finished_at' => $latestTick?->finished_at?->toDateTimeString(),
                'failed_task_count' => count($health['failed_tasks']),
                'stuck_task_count' => count($health['stuck_tasks']),
            ],
        );
    }

    /* ─── Email fanout ────────────────────────────────────────────── */

    private function emailCritical(Alert $alert): void
    {
        $users = collect();
        try {
            $catalog = (array) $this->settings->get('alerts.critical.notification_roles', []);
            $roleSlugs = array_values(array_filter(
                (array) ($catalog[$alert->type->value] ?? []),
                static fn ($role): bool => is_string($role) && $role !== '',
            ));
            if ($roleSlugs === []) return;

            $users = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roleSlugs))
                ->where('is_active', true)
                ->get();

            if ($users->isEmpty()) {
                return;
            }

            $emailUsers = $users->filter(static fn (User $user): bool => filter_var($user->email, FILTER_VALIDATE_EMAIL));
            if ($emailUsers->isEmpty()) {
                app(EmailDeliveryFailureNotifier::class)->notify(
                    $users,
                    'Critical alert',
                    "Critical alert '{$alert->title}' could not be emailed because no configured recipient has a usable address. Review the alert immediately.",
                    [
                        'link_to' => '/alerts',
                        'entity_type' => 'alert',
                        'entity_id' => $alert->hash_id,
                        'reason' => 'No critical-alert recipient has a usable email address.',
                    ],
                );

                return;
            }

            Notification::send($emailUsers, new CriticalAlertEmail($alert));
            $alert->update(['notified_email_at' => now()]);
        } catch (\Throwable $e) {
            app(EmailDeliveryFailureNotifier::class)->notify(
                $users,
                'Critical alert',
                "Critical alert '{$alert->title}' could not be delivered by email. Review the alert immediately.",
                [
                    'link_to' => '/alerts',
                    'entity_type' => 'alert',
                    'entity_id' => $alert->hash_id,
                    'reason' => 'The email provider rejected or could not deliver the critical alert.',
                ],
            );
            Log::warning('AlertEngine: critical email failed', ['error' => $e->getMessage(), 'alert_id' => $alert->id]);
        }
    }

    private function positiveIntSetting(string $key): int
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }
        return (int) $value;
    }

    private function nonNegativeIntSetting(string $key): int
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (int) $value < 0) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }
        return (int) $value;
    }

    private function ratioSetting(string $key): float
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }
        return (float) $value;
    }
}
