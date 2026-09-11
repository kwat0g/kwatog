<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\Alert;
use App\Common\Notifications\CriticalAlertEmail;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Task A2 — Smart Alert Engine.
 *
 * runAllChecks() iterates every monitored threshold and raises alerts. Each
 * condition has a stable identity and the database permits only one open row
 * for that identity. Successful checks also resolve open conditions that are
 * no longer observed; a failed check leaves its previous state untouched.
 *
 * Critical alerts fan out an email through a durable, bounded retry state
 * machine. Delivery failures are logged and surfaced in-app without aborting
 * threshold evaluation.
 */
class AlertEngineService
{
    private const CHECK_CHUNK_SIZE = 500;

    private const MAX_CRITICAL_EMAIL_ATTEMPTS = 5;

    /** @var array{created:list<array{type:string,severity:string}>,observed:array<string,list<string>>}|null */
    private ?array $runContext = null;

    private ?string $currentCheck = null;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SchedulerExecutionLedger $scheduler,
    ) {}

    /**
     * Raise or refresh one condition. The unique open-condition index is the
     * final authority when two workers race to create the first row.
     */
    public function raise(
        AlertType $type,
        AlertSeverity $severity,
        string $title,
        string $message,
        ?Model $entity = null,
        array $metadata = [],
    ): Alert {
        $entityType = $entity?->getMorphClass();
        $entityId = $entity?->getKey();
        $conditionKey = Alert::conditionKeyFor($type, $entityType, $entityId);

        if ($this->currentCheck !== null && $this->runContext !== null) {
            $this->runContext['observed'][$this->currentCheck][] = $conditionKey;
        }

        try {
            [$alert, $created] = DB::transaction(function () use (
                $type,
                $severity,
                $title,
                $message,
                $entityType,
                $entityId,
                $metadata,
                $conditionKey,
            ): array {
                $existing = Alert::query()
                    ->where('condition_key', $conditionKey)
                    ->whereNull('resolved_at')
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $existing->forceFill([
                        'type' => $type->value,
                        'severity' => $severity->value,
                        'title' => $title,
                        'message' => $message,
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                        'metadata' => $metadata,
                    ])->save();

                    return [$existing->fresh(), false];
                }

                return [Alert::create([
                    'type' => $type->value,
                    'severity' => $severity->value,
                    'title' => $title,
                    'message' => $message,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'condition_key' => $conditionKey,
                    'metadata' => $metadata,
                ]), true];
            });
        } catch (QueryException $e) {
            // PostgreSQL aborts the transaction that observes a unique
            // violation, so recovery must happen after that transaction has
            // rolled back rather than inside its failed closure.
            if (! $this->isConditionConflict($e)) {
                throw $e;
            }

            $alert = DB::transaction(fn (): Alert => Alert::query()
                ->where('condition_key', $conditionKey)
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->firstOrFail());
            $created = false;
        }

        if ($created && $this->runContext !== null) {
            $this->runContext['created'][] = [
                'type' => $type->value,
                'severity' => $severity->value,
            ];
        }

        if ($severity === AlertSeverity::Critical) {
            $this->attemptCriticalEmail($alert);
        }

        return $alert;
    }

    public function dismiss(Alert $alert, User $user): Alert
    {
        return DB::transaction(function () use ($alert, $user): Alert {
            $locked = Alert::query()->lockForUpdate()->findOrFail($alert->getKey());
            $locked->update([
                'is_dismissed' => true,
                'dismissed_by' => $user->id,
                'dismissed_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function markRead(Alert $alert): Alert
    {
        return DB::transaction(function () use ($alert): Alert {
            $locked = Alert::query()->lockForUpdate()->findOrFail($alert->getKey());
            if (! $locked->is_read) {
                $locked->update(['is_read' => true]);
            }

            return $locked->fresh();
        });
    }

    /**
     * Retry pending/failed critical deliveries without creating another alert.
     * Rows stuck in `sending` after a worker crash become eligible after the
     * same bounded lease used by the retry sweep.
     *
     * @return array{attempted:int,sent:int,failed:int}
     */
    public function retryCriticalEmails(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $staleBefore = now()->subMinutes(15);
        $stats = ['attempted' => 0, 'sent' => 0, 'failed' => 0];

        Alert::query()
            ->where('severity', AlertSeverity::Critical->value)
            ->whereNull('notified_email_at')
            ->whereIn('email_status', ['pending', 'failed', 'sending'])
            ->where('email_attempts', '<', self::MAX_CRITICAL_EMAIL_ATTEMPTS)
            ->where(function ($query) use ($staleBefore): void {
                $query->whereIn('email_status', ['pending', 'failed'])
                    ->where(function ($query): void {
                        $query->whereNull('email_next_attempt_at')
                            ->orWhere('email_next_attempt_at', '<=', now());
                    })
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query->where('email_status', 'sending')
                            ->where('email_last_attempt_at', '<=', $staleBefore);
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Alert $alert) use (&$stats): void {
                $stats['attempted']++;
                $before = $alert->notified_email_at;
                $this->attemptCriticalEmail($alert);
                $fresh = $alert->fresh();
                if ($before === null && $fresh?->notified_email_at !== null) {
                    $stats['sent']++;
                } elseif ($fresh?->email_status === 'failed' || $fresh?->email_status === 'terminal_failed') {
                    $stats['failed']++;
                }
            });

        return $stats;
    }

    public function pruneResolved(int $months = 12): int
    {
        $cutoff = now()->subMonths(max(1, $months));

        return Alert::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', $cutoff)
            ->delete();
    }

    /**
     * @return array{raised:int,by_severity:array<string,int>,by_type:array<string,int>,failed:array<int,string>}
     */
    public function runAllChecks(): array
    {
        $this->runContext = ['created' => [], 'observed' => []];
        $stats = ['raised' => 0, 'by_severity' => [], 'by_type' => [], 'failed' => []];

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

        foreach (AlertSeverity::values() as $sev) {
            $stats['by_severity'][$sev] = 0;
        }

        foreach (AlertType::values() as $type) {
            $stats['by_type'][$type] = 0;
        }

        foreach ($this->runContext['created'] as $created) {
            $stats['raised']++;
            $stats['by_severity'][$created['severity']]++;
            $stats['by_type'][$created['type']]++;
        }

        $this->runContext = null;

        return $stats;
    }

    private function safe(callable $fn, string $label): ?string
    {
        $this->currentCheck = $label;
        try {
            $fn();
            $this->resolveMissingConditions($label);

            return null;
        } catch (\Throwable $e) {
            Log::warning("AlertEngine: {$label} check failed", ['error' => $e->getMessage()]);

            return $label;
        } finally {
            $this->currentCheck = null;
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
            ->chunkById(self::CHECK_CHUNK_SIZE, function ($items): void {
                $lowStockItemIds = $items
                    ->filter(function (Item $item): bool {
                        $onHand = (float) ($item->on_hand ?? 0);

                        return (float) $item->reorder_point > 0 && $onHand < (float) $item->reorder_point;
                    })
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                $supplierItemIds = $lowStockItemIds === []
                    ? []
                    : ApprovedSupplier::query()
                        ->qualified()
                        ->whereIn('item_id', $lowStockItemIds)
                        ->pluck('item_id')
                        ->map(static fn ($id): int => (int) $id)
                        ->all();
                $hasSupplier = array_fill_keys($supplierItemIds, true);

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

                        if (! isset($hasSupplier[(int) $item->id])) {
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
            }, 'items.id', 'id');
    }

    /* ─── Production checks ───────────────────────────────────────── */

    private function checkProduction(): void
    {
        $moldWarningRatio = $this->ratioSetting('alerts.mold.warning_ratio');
        $moldCriticalRatio = $this->ratioSetting('alerts.mold.critical_ratio');
        if ($moldWarningRatio >= $moldCriticalRatio) {
            throw new BusinessRuleException('Mold warning ratio must be lower than its critical ratio.');
        }
        // Machine breakdowns
        Machine::where('status', 'breakdown')
            ->chunkById(self::CHECK_CHUNK_SIZE, function ($machines): void {
                foreach ($machines as $machine) {
                    $this->raise(
                        AlertType::MachineBreakdown,
                        AlertSeverity::Critical,
                        "Machine breakdown: {$machine->machine_code}",
                        "{$machine->name} is reporting status 'breakdown'. Production halted on this machine.",
                        $machine,
                        ['machine_code' => $machine->machine_code],
                    );
                }
            });

        // Mold shot thresholds from the configured warning and critical ratios.
        Mold::query()
            ->whereNotNull('max_shots_before_maintenance')
            ->where('max_shots_before_maintenance', '>', 0)
            ->chunkById(self::CHECK_CHUNK_SIZE, function ($molds) use ($moldWarningRatio, $moldCriticalRatio): void {
                foreach ($molds as $mold) {
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
            ->chunkById(self::CHECK_CHUNK_SIZE, function ($workOrders): void {
                foreach ($workOrders as $workOrder) {
                    $hours = abs(now()->diffInHours($workOrder->planned_end));
                    $this->raise(
                        AlertType::WoOverdue,
                        AlertSeverity::Warning,
                        "Work order overdue: {$workOrder->wo_number}",
                        "{$workOrder->wo_number} planned end was {$workOrder->planned_end?->toDateTimeString()} ({$hours}h overdue).",
                        $workOrder,
                        ['hours_overdue' => (int) $hours, 'status' => (string) ($workOrder->status?->value ?? $workOrder->status)],
                    );
                }
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
            ->join('machines as m', 'm.id', '=', 'w.machine_id')
            ->whereNotNull('w.machine_id')
            ->where('wo.recorded_at', '>=', $cutoff)
            ->groupBy('m.id', 'm.machine_code', 'm.name')
            ->select(
                'm.id',
                'm.machine_code',
                'm.name',
                DB::raw('SUM(wo.good_count) as good'),
                DB::raw('SUM(wo.reject_count) as reject'),
            )
            ->orderBy('m.id')
            ->cursor();

        foreach ($rows as $row) {
            $total = (int) ($row->good + $row->reject);
            if ($total < $minimumOutput) {
                continue;
            } // not enough data
            $quality = (int) $row->good / max(1, $total);
            if ($quality < $oeeThreshold) {
                $machine = (new Machine)->newFromBuilder([
                    'id' => $row->id,
                    'machine_code' => $row->machine_code,
                    'name' => $row->name,
                ]);
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

    /* ─── Finance checks ──────────────────────────────────────────── */

    private function checkFinance(): void
    {
        $today = Carbon::today();
        $warningDays = $this->positiveIntSetting('alerts.ar.warning_overdue_days');
        $criticalDays = $this->positiveIntSetting('alerts.ar.critical_overdue_days');
        $apDueSoonDays = $this->nonNegativeIntSetting('alerts.ap.due_soon_days');
        if ($warningDays >= $criticalDays) {
            throw new BusinessRuleException('AR warning days must be lower than AR critical days.');
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
            ->orderBy('id')
            ->chunkById(self::CHECK_CHUNK_SIZE, function ($rows) use ($today, $criticalDays): void {
                foreach ($rows as $row) {
                    $invoice = (new Invoice)->newFromBuilder((array) $row);

                    // Use an absolute day distance so Carbon's signed diff
                    // direction cannot turn an overdue value negative.
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
                }
            }, 'id', 'id');

        // AP due-soon is an inclusive window: due today through the configured
        // number of days ahead. Overdue bills are not due-soon alerts.
        DB::table('bills')
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])
            ->whereDate('due_date', '>=', $today->toDateString())
            ->whereDate('due_date', '<=', $today->copy()->addDays($apDueSoonDays)->toDateString())
            ->select('id', 'bill_number', 'due_date', 'balance', 'vendor_id')
            ->orderBy('id')
            ->chunkById(self::CHECK_CHUNK_SIZE, function ($rows) use ($today): void {
                foreach ($rows as $row) {
                    $bill = (new Bill)->newFromBuilder((array) $row);
                    $dueInDays = (int) Carbon::parse($row->due_date)->diffInDays($today, true);
                    $this->raise(
                        AlertType::ApDueSoon,
                        AlertSeverity::Info,
                        "Bill due soon: {$row->bill_number}",
                        "Bill {$row->bill_number} is due in {$dueInDays} day(s) ({$row->due_date}). Balance ".app(CurrencyDisplayService::class)->format($row->balance).'.',
                        $bill,
                        ['due_date' => $row->due_date, 'due_in_days' => $dueInDays, 'balance' => (float) $row->balance],
                    );
                }
            }, 'id', 'id');
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
            ->join('products as p', 'p.id', '=', 'w.product_id')
            ->where('wo.recorded_at', '>=', now()->subHours($lookbackHours))
            ->groupBy('p.id', 'p.part_number', 'p.name')
            ->select(
                'p.id',
                'p.part_number',
                'p.name',
                DB::raw('SUM(wo.good_count) as good'),
                DB::raw('SUM(wo.reject_count) as reject'),
            )
            ->orderBy('p.id')
            ->cursor();

        foreach ($rows as $row) {
            $total = (int) ($row->good + $row->reject);
            if ($total < $minimumOutput) {
                continue;
            }
            $scrap = $row->reject / max(1, $total);
            if ($scrap > $scrapThreshold) {
                $product = (new Product)->newFromBuilder([
                    'id' => $row->id,
                    'part_number' => $row->part_number,
                    'name' => $row->name,
                ]);
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
     * `raise()` refreshes the one open scheduler condition by stable type and
     * null entity identity. The latest ledger issues replace the old message,
     * so an operator sees the current degraded reason rather than a stale
     * first-detection snapshot.
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

    private function resolveMissingConditions(string $check): void
    {
        if ($this->runContext === null) {
            return;
        }

        $types = array_map(
            static fn (AlertType $type): string => $type->value,
            match ($check) {
                'inventory' => [AlertType::StockCritical, AlertType::StockLow, AlertType::NoSupplier],
                'production' => [
                    AlertType::MachineBreakdown,
                    AlertType::MoldShotLimit,
                    AlertType::MoldShotCritical,
                    AlertType::WoOverdue,
                    AlertType::OeeBelowThreshold,
                ],
                'finance' => [AlertType::ArOverdue30, AlertType::ArOverdue60, AlertType::ApDueSoon],
                'quality' => [AlertType::QcFailRateHigh],
                'scheduler' => [AlertType::SchedulerStale],
                default => [],
            },
        );

        if ($types === []) {
            return;
        }

        $observed = array_values(array_unique($this->runContext['observed'][$check] ?? []));
        $query = Alert::query()
            ->whereNull('resolved_at')
            ->whereIn('type', $types);

        if ($observed !== []) {
            $query->whereNotIn('condition_key', $observed);
        }

        $query->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    private function isConditionConflict(QueryException $e): bool
    {
        $message = $e->getMessage();

        return in_array((string) $e->getCode(), ['23505', '23000'], true)
            && str_contains($message, 'alerts_one_open_condition_unique');
    }

    /* ─── Email fanout ────────────────────────────────────────────── */

    private function attemptCriticalEmail(Alert $alert): void
    {
        $claimed = $this->claimCriticalEmail($alert->getKey());
        if ($claimed === null) {
            return;
        }

        $users = collect();
        try {
            $catalog = (array) $this->settings->get('alerts.critical.notification_roles', []);
            $roleSlugs = array_values(array_filter(
                (array) ($catalog[$claimed->type->value] ?? []),
                static fn ($role): bool => is_string($role) && $role !== '',
            ));
            if ($roleSlugs === []) {
                throw new \RuntimeException("No critical-alert recipient roles are configured for {$claimed->type->value}.");
            }

            $users = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roleSlugs))
                ->where('is_active', true)
                ->get();

            if ($users->isEmpty()) {
                throw new \RuntimeException('No active critical-alert recipients were found.');
            }

            $emailUsers = $users->filter(static fn (User $user): bool => filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false);
            if ($emailUsers->isEmpty()) {
                throw new \RuntimeException('No configured critical-alert recipient has a usable email address.');
            }

            Notification::send($emailUsers, new CriticalAlertEmail($claimed));
            Alert::query()->whereKey($claimed->getKey())->update([
                'email_status' => 'sent',
                'notified_email_at' => now(),
                'email_next_attempt_at' => null,
                'email_failed_at' => null,
                'email_last_error' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $attempts = (int) $claimed->email_attempts;
            $terminal = $attempts >= self::MAX_CRITICAL_EMAIL_ATTEMPTS;
            $error = Str::limit($e->getMessage(), 1000, '');

            Alert::query()->whereKey($claimed->getKey())->update([
                'email_status' => $terminal ? 'terminal_failed' : 'failed',
                'email_failed_at' => now(),
                'email_next_attempt_at' => $terminal ? null : now()->addMinutes($this->emailBackoffMinutes($attempts)),
                'email_last_error' => $error,
                'updated_at' => now(),
            ]);

            try {
                app(EmailDeliveryFailureNotifier::class)->notify(
                    $users,
                    'Critical alert',
                    "Critical alert '{$claimed->title}' could not be delivered by email. Review the alert immediately.",
                    [
                        'link_to' => '/alerts',
                        'entity_type' => 'alert',
                        'entity_id' => $claimed->hash_id,
                        'reason' => $error,
                    ],
                );
            } catch (\Throwable $fallbackError) {
                Log::warning('AlertEngine: critical email fallback failed', [
                    'error' => $fallbackError->getMessage(),
                    'alert_id' => $claimed->id,
                ]);
            }
            Log::warning('AlertEngine: critical email failed', [
                'error' => $e->getMessage(),
                'alert_id' => $claimed->id,
                'attempt' => $attempts,
                'terminal' => $terminal,
            ]);
        }
    }

    private function claimCriticalEmail(int|string $alertId): ?Alert
    {
        return DB::transaction(function () use ($alertId): ?Alert {
            $alert = Alert::query()->lockForUpdate()->find($alertId);
            if ($alert === null || $alert->notified_email_at !== null) {
                return null;
            }

            $attempts = (int) $alert->email_attempts;
            if ($attempts >= self::MAX_CRITICAL_EMAIL_ATTEMPTS || $alert->email_status === 'terminal_failed') {
                return null;
            }

            $now = now();
            if ($alert->email_status === 'sending'
                && $alert->email_last_attempt_at?->gt($now->copy()->subMinutes(15))) {
                return null;
            }
            if ($alert->email_status !== 'sending'
                && $alert->email_next_attempt_at?->gt($now)) {
                return null;
            }

            $alert->forceFill([
                'email_status' => 'sending',
                'email_attempts' => $attempts + 1,
                'email_last_attempt_at' => $now,
                'email_next_attempt_at' => null,
            ])->save();

            return $alert->fresh();
        });
    }

    private function emailBackoffMinutes(int $attempt): int
    {
        return match (min(max($attempt, 1), self::MAX_CRITICAL_EMAIL_ATTEMPTS)) {
            1 => 5,
            2 => 15,
            3 => 60,
            4 => 240,
            default => 1440,
        };
    }

    private function positiveIntSetting(string $key): int
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }

        return (int) $value;
    }

    private function nonNegativeIntSetting(string $key): int
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (int) $value < 0) {
            throw new BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }

        return (int) $value;
    }

    private function ratioSetting(string $key): float
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
            throw new BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }

        return (float) $value;
    }
}
