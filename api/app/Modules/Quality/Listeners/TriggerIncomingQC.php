<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\ItemQualityPlanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

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
 * Best-effort.
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
            $grn = $event->grn->loadMissing(['items.item', 'receiver']);

            $legacyInspectionExists = Inspection::query()
                ->where('stage', InspectionStage::Incoming->value)
                ->where('entity_type', InspectionEntityType::Grn->value)
                ->where('entity_id', $grn->id)
                ->whereNull('grn_item_id')
                ->exists();
            if ($legacyInspectionExists) {
                return;
            }

            $created = 0;
            foreach ($grn->items as $line) {
                if (! $line->item) {
                    continue;
                }
                $exists = Inspection::query()
                    ->where('stage', InspectionStage::Incoming->value)
                    ->where('grn_item_id', $line->id)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $batchQuantity = (int) (float) $line->quantity_received;
                if ($batchQuantity < 1) {
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

            if ($created === 0) {
                return;
            }

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
        } catch (\Throwable $e) {
            Log::warning('TriggerIncomingQC failed', [
                'grn_id' => $event->grn->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
