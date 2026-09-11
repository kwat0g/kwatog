<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Honest ASL backfill (2026-09-11).
 *
 * Scans historical purchase-order lines and records a `provisional`
 * approved_suppliers link for every item+vendor pair that does not already
 * have a live row. Provisional links are suggestive only — they make the
 * historical relationship visible for purchasing to review without blessing
 * the vendor for the item.
 *
 * Existing rows are NEVER rewritten: an `approved` link stays approved, and a
 * soft-deleted pair may be re-created (the partial unique index is scoped to
 * live rows). Cancelled / soft-deleted POs are ignored.
 *
 * Idempotent. Use --dry-run to see what would be created.
 */
class BackfillApprovedSuppliersCommand extends Command
{
    protected $signature = 'purchasing:backfill-approved-suppliers
        {--dry-run : Report the links that would be created without writing}';

    protected $description = 'Create provisional approved-supplier links from historical purchase-order lines.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $created = 0;
        $existing = 0;

        $pairs = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereNull('purchase_orders.deleted_at')
            ->where('purchase_orders.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereNotNull('purchase_order_items.item_id')
            ->distinct()
            ->select('purchase_order_items.item_id', 'purchase_orders.vendor_id')
            ->cursor();

        foreach ($pairs as $pair) {
            $scanned++;

            $live = ApprovedSupplier::query()
                ->where('item_id', $pair->item_id)
                ->where('vendor_id', $pair->vendor_id)
                ->exists();

            if ($live) {
                $existing++;
                continue;
            }

            if ($dryRun) {
                $created++;
                continue;
            }

            try {
                ApprovedSupplier::create([
                    'item_id' => $pair->item_id,
                    'vendor_id' => $pair->vendor_id,
                    'qualification_status' => ApprovedSupplier::QUALIFICATION_PROVISIONAL,
                    'lead_time_days' => 0,
                ]);
                $created++;
            } catch (QueryException) {
                // Lost a race to a concurrent writer; the live row now exists.
                $existing++;
            }
        }

        $this->info(sprintf(
            '%sScanned %d item/vendor pair(s): created=%d existing=%d.',
            $dryRun ? '[DRY RUN] ' : '',
            $scanned,
            $created,
            $existing,
        ));

        return self::SUCCESS;
    }
}
