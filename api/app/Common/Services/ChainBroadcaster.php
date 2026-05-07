<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\ChainStepAdvanced;
use App\Common\Support\ChainDefinitions;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Series C — Task C4. Central helper for broadcasting chain step advances.
 *
 * Every domain service (SalesOrderService, WorkOrderService, GrnService,
 * DeliveryService, …) calls broadcastFor() after committing a status
 * change. The mapping between Eloquent class and chain entity-type slug
 * lives here so individual services don't need to know the slug.
 *
 * Failures are swallowed and logged: a broken broadcast must never roll
 * back a successful business transaction. Reverb may be down, queue may
 * be paused, etc. — none of those should block confirming a sales order.
 */
class ChainBroadcaster
{
    /** @var array<class-string<Model>, string> */
    private const CLASS_TO_TYPE = [
        \App\Modules\CRM\Models\SalesOrder::class           => 'sales_order',
        \App\Modules\Production\Models\WorkOrder::class     => 'work_order',
        \App\Modules\Purchasing\Models\PurchaseOrder::class => 'purchase_order',
        \App\Modules\SupplyChain\Models\Delivery::class     => 'delivery',
        \App\Modules\Inventory\Models\GoodsReceiptNote::class => 'grn',
    ];

    /** @var array<class-string<Model>, string> */
    private const DOC_NUMBER_FIELD = [
        \App\Modules\CRM\Models\SalesOrder::class           => 'so_number',
        \App\Modules\Production\Models\WorkOrder::class     => 'wo_number',
        \App\Modules\Purchasing\Models\PurchaseOrder::class => 'po_number',
        \App\Modules\SupplyChain\Models\Delivery::class     => 'delivery_number',
        \App\Modules\Inventory\Models\GoodsReceiptNote::class => 'grn_number',
    ];

    /**
     * Fire a ChainStepAdvanced event for $entity transitioning to $newStatus.
     *
     * Returns true on dispatch, false on swallowed failure.
     */
    public function broadcastFor(Model $entity, string $newStatus, ?User $actor = null): bool
    {
        try {
            $cls = $entity::class;
            $type = self::CLASS_TO_TYPE[$cls] ?? null;
            if ($type === null) {
                Log::debug('ChainBroadcaster: unsupported model class', ['class' => $cls]);
                return false;
            }

            $hashId = method_exists($entity, 'getHashIdAttribute')
                ? (string) $entity->hash_id
                : (string) $entity->getKey();

            $docField  = self::DOC_NUMBER_FIELD[$cls] ?? null;
            $docNumber = $docField !== null
                ? (string) ($entity->{$docField} ?? '')
                : (string) $entity->getKey();

            [$active, $completed] = ChainDefinitions::resolve($type, $newStatus);

            event(new ChainStepAdvanced(
                entityType:     $type,
                entityHashId:   $hashId,
                docNumber:      $docNumber,
                newStatus:      $newStatus,
                activeStep:     $active,
                completedSteps: $completed,
                actorName:      $actor?->name,
            ));

            return true;
        } catch (\Throwable $e) {
            Log::warning('ChainBroadcaster failed', [
                'class'      => $entity::class,
                'new_status' => $newStatus,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }
}
