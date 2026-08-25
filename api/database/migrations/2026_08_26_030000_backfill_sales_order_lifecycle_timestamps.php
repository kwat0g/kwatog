<?php

declare(strict_types=1);

use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M033 F-008 (historical half) — backfill the sales-order lifecycle timestamps
 * added by 2026_08_25_100000_add_sales_order_lifecycle_timestamps.
 *
 * That migration added six nullable columns but no backfill, so every order
 * that transitioned before it shipped renders a blank date in the chain
 * projection (SalesOrderService::chain() reads confirmed_at, in_production_at,
 * partially_delivered_at, delivered_at and invoiced_at directly).
 *
 * The dates are recovered from `audit_logs`, not invented. SalesOrder uses
 * HasAuditLog, which writes one row per create/update with `new_values` set to
 * the changed attributes, so the moment a status was first written is recorded
 * as `min(created_at)` over the rows whose new_values->>'status' names that
 * status. `created` rows count too: an order that came into existence already
 * at a status (seeded or imported) genuinely reached it at that moment.
 *
 * Deliberately NOT done: no fallback to `updated_at`/`created_at` when the
 * audit trail has no matching row. These columns feed a chain projection an
 * operator reads as "this is when it happened"; a plausible-looking guess in an
 * audit-relevant field is worse than an honest blank. Orders predating the
 * audit trail therefore stay NULL.
 *
 * Only NULL columns are written, so this is idempotent and can never overwrite
 * a value the service recorded authoritatively.
 *
 * NOTE ON THE FILENAME: this is timestamp-style rather than the preferred
 * 0NNN_ numbering because the migrator sorts by full filename, and every
 * `0NNN_` name sorts BEFORE every `2026_` one. A `0479_` file would run before
 * the migration that adds these columns and fail on a fresh database.
 */
return new class extends Migration
{
    /**
     * Lifecycle column → the SO status whose first appearance dates it.
     *
     * `draft` has no column: it is the creation state, already dated by
     * created_at. `cancelled_at` is included — cancel() writes status the same
     * way, so the audit trail dates it identically.
     *
     * @var array<string, string>
     */
    private const COLUMN_STATUS = [
        'confirmed_at'           => 'confirmed',
        'in_production_at'       => 'in_production',
        'partially_delivered_at' => 'partially_delivered',
        'delivered_at'           => 'delivered',
        'invoiced_at'            => 'invoiced',
        'cancelled_at'           => 'cancelled',
    ];

    public function up(): void
    {
        foreach (self::COLUMN_STATUS as $column => $status) {
            DB::update(
                <<<SQL
                UPDATE sales_orders so
                   SET {$column} = src.first_seen_at
                  FROM (
                        SELECT model_id, MIN(created_at) AS first_seen_at
                          FROM audit_logs
                         WHERE model_type = ?
                           AND new_values IS NOT NULL
                           AND new_values ->> 'status' = ?
                      GROUP BY model_id
                       ) AS src
                 WHERE so.id = src.model_id
                   AND so.{$column} IS NULL
                SQL,
                [SalesOrder::class, $status],
            );
        }
    }

    /**
     * Not reversible on purpose. The backfill only ever filled NULLs, and the
     * rows it filled are not marked, so nulling the columns again would also
     * discard timestamps the service wrote authoritatively. The columns
     * themselves are dropped by the migration that added them.
     */
    public function down(): void
    {
        // No-op — see the docblock above.
    }
};
