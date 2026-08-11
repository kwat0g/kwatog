<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Common\Services\ChainListenerRunService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\ItemQualityPlanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Series C — Task C2. Incoming QC auto-trigger.
 *
 * GRN created → an incoming inspection must precede stock acceptance
 * (per IATF — resin certs, moisture, dimensional checks). This listener
 * creates one pending inspection per received inventory line.
 * The QC inspector picks it up; on pass the GRN service is then
 * cleared to accept (assertQcGate already enforces this).
 *
 * Idempotent: skips if any inspection already exists pointing at the GRN.
 * Stateful failures are rethrown for queue retry; notifications are
 * best-effort. The GRN row lock serializes synchronous and queued delivery of
 * the same event before the per-line unique constraint is consulted.
 */
class TriggerIncomingQC implements ShouldQueue
{
    public function __construct(
        private readonly InspectionService $inspections,
        private readonly ItemQualityPlanService $qualityPlans,
        private readonly ?SettingsService $settings = null,
    ) {}

    public function handle(GoodsReceiptNoteCreated $event): void
    {
        try {
            [$grn, $created, $existing, $eligible] = DB::transaction(function () use ($event): array {
                $grn = GoodsReceiptNote::query()
                    ->lockForUpdate()
                    ->find($event->grn->id);
                if (! $grn || $grn->status !== GrnStatus::PendingQc) {
                    return [null, 0, 0, 0];
                }

                $grn->loadMissing(['items.item', 'receiver']);

                $legacyInspectionExists = Inspection::query()
                    ->where('stage', InspectionStage::Incoming->value)
                    ->where('entity_type', InspectionEntityType::Grn->value)
                    ->where('entity_id', $grn->id)
                    ->whereNull('grn_item_id')
                    ->exists();
                if ($legacyInspectionExists) {
                    return [$grn, 0, 1, 1];
                }

                $created = 0;
                $existing = 0;
                $eligible = 0;
                foreach ($grn->items as $line) {
                    if (! $line->item) {
                        continue;
                    }

                    $batchQuantity = (int) (float) $line->quantity_received;
                    if ($batchQuantity < 1) {
                        continue;
                    }
                    $eligible++;

                    $hasInspection = Inspection::query()
                        ->where('stage', InspectionStage::Incoming->value)
                        ->where('grn_item_id', $line->id)
                        ->exists();
                    if ($hasInspection) {
                        $existing++;
                        continue;
                    }

                    $plan = $this->qualityPlans->activeFor(
                        $line->item,
                        $grn->vendor_id,
                        $grn->received_date?->toDateString(),
                    );

                    if ($plan) {
                        $this->inspections->createIncomingFromPlan($plan, $line, $grn, $grn->receiver);
                    } else {
                        $this->inspections->createIncomingForItem(
                            $line->item,
                            $batchQuantity,
                            $grn->id,
                            $grn->receiver,
                            "No active quality plan; fallback verdict for GRN {$grn->grn_number}.",
                            $line->id,
                        );
                    }
                    $created++;
                }

                return [$grn, $created, $existing, $eligible];
            });
        } catch (BusinessRuleException|ModelNotFoundException|InvalidArgumentException $e) {
            app(GrnService::class)->markIncomingQcHandoffManual(
                $event->grn->id,
                'Incoming QC staging requires manual action: '.$e->getMessage(),
            );
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'incoming_qc_manual_required',
                'Fix the Quality setup, then replay this GRN incoming-QC handoff.',
            );
            return;
        } catch (\Throwable $e) {
            app(GrnService::class)->markIncomingQcHandoffPending(
                $event->grn->id,
                'Incoming QC staging failed and is waiting for retry: '.$e->getMessage(),
            );
            Log::error('TriggerIncomingQC failed unexpectedly', [
                'grn_id' => $event->grn->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        if (! $grn || $created === 0) {
            if ($grn && $eligible === 0 && $existing === 0) {
                app(GrnService::class)->markIncomingQcHandoffNotRequired($grn->id);
            } elseif ($grn && $existing > 0) {
                app(GrnService::class)->markIncomingQcHandoffGenerated($grn->id);
            }
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                $grn && $eligible === 0
                    ? 'incoming_qc_not_required'
                    : 'incoming_inspections_already_staged',
            );
            return;
        }

        app(GrnService::class)->markIncomingQcHandoffGenerated($grn->id);

        try {
            $roles = array_values(array_filter((array) ($this->settings ?? app(SettingsService::class))->get('quality.incoming_qc.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $recipients = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            app(NotificationService::class)->send($recipients, 'chain.incoming_qc_required', [
                'title'       => 'Incoming QC required',
                'message'     => "Incoming QC required for GRN {$grn->grn_number}.",
                'link_to'     => "/inventory/grns/{$grn->hash_id}",
                'entity_type' => 'grn',
                'entity_id'   => $grn->hash_id,
                'grn_number'  => $grn->grn_number,
            ]);
        } catch (\Throwable $e) {
            Log::debug('TriggerIncomingQC notification failed', ['error' => $e->getMessage()]);
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'incoming_inspections_created',
            "Created {$created} incoming inspection(s) for GRN {$grn->grn_number}.",
        );
    }
}
