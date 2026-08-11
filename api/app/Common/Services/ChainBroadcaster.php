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
 * DeliveryService, …) calls broadcastFor() while its status transaction is
 * still open. The mapping between Eloquent class and chain entity-type slug
 * lives here so individual services don't need to know the slug. OutboxService
 * defers queue publication until the outermost commit.
 *
 * Durable staging failures are logged and rethrown: a status mutation must
 * not commit without its canonical chain evidence. Queue/Reverb outages do
 * not reach this boundary — OutboxService only attempts queue publication
 * after commit and leaves the row recoverable when delivery is unavailable.
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
        \App\Modules\Accounting\Models\Bill::class           => 'bill',
        \App\Modules\Accounting\Models\Invoice::class       => 'invoice',
    ];

    /** @var array<class-string<Model>, string> */
    private const DOC_NUMBER_FIELD = [
        \App\Modules\CRM\Models\SalesOrder::class           => 'so_number',
        \App\Modules\Production\Models\WorkOrder::class     => 'wo_number',
        \App\Modules\Purchasing\Models\PurchaseOrder::class => 'po_number',
        \App\Modules\SupplyChain\Models\Delivery::class     => 'delivery_number',
        \App\Modules\Inventory\Models\GoodsReceiptNote::class => 'grn_number',
        \App\Modules\Accounting\Models\Bill::class           => 'bill_number',
        \App\Modules\Accounting\Models\Invoice::class       => 'invoice_number',
    ];

    /**
     * Stage a ChainStepAdvanced event for $entity transitioning to $newStatus.
     * Call this from the transaction that owns the status mutation; the
     * durable outbox row is then committed atomically and published later.
     *
     * Returns true when the durable row is staged. Unsupported model classes
     * and outbox/database failures throw so the owning transaction can roll
     * back rather than commit an untracked chain transition.
     */
    public function broadcastFor(Model $entity, string $newStatus, ?User $actor = null): bool
    {
        try {
            $cls = $entity::class;
            $type = self::CLASS_TO_TYPE[$cls] ?? null;
            if ($type === null) {
                throw new \InvalidArgumentException(
                    "ChainBroadcaster does not support model class {$cls}."
                );
            }

            $hashId = method_exists($entity, 'getHashIdAttribute')
                ? (string) $entity->hash_id
                : (string) $entity->getKey();

            $docField  = self::DOC_NUMBER_FIELD[$cls] ?? null;
            $docNumber = $docField !== null
                ? (string) ($entity->{$docField} ?? '')
                : (string) $entity->getKey();

            [$active, $completed] = ChainDefinitions::resolveStrict($type, $newStatus);

            $event = new ChainStepAdvanced(
                entityType:     $type,
                entityHashId:   $hashId,
                docNumber:      $docNumber,
                newStatus:      $newStatus,
                activeStep:     $active,
                completedSteps: $completed,
                actorName:      $actor?->name,
            );

            $chain = in_array($type, ['purchase_order', 'grn', 'bill'], true)
                ? 'p2p'
                : 'o2c';
            $version = (string) ($entity->getRawOriginal('updated_at') ?? microtime(true));
            $dedupeKey = 'chain-step:'.hash(
                'sha256',
                implode('|', [$type, (string) $entity->getKey(), $newStatus, $version]),
            );

            // Realtime delivery is now durable too. If Reverb is unavailable,
            // the outbox worker retries the broadcast without affecting the
            // already-committed business transition.
            app(OutboxService::class)->recordForChain(
                $event,
                $entity,
                $chain,
                $type,
                $active,
                $dedupeKey,
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('ChainBroadcaster durable staging failed', [
                'class'      => $entity::class,
                'new_status' => $newStatus,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
