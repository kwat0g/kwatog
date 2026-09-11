<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier response states + confirmed delivery date (2026-09-11).
 *
 * `sent` historically doubled as "the supplier acknowledged"; the supplier's
 * acceptance is now `acknowledged` and its counter/refusal are
 * `supplier_proposed` / `supplier_declined`. This extends the PO status CHECK
 * constraint (created by 2026_08_13_220000) and adds `confirmed_delivery_date`
 * so the supplier's agreed date is recorded separately from OGAMI's required
 * date (`expected_delivery_date`), which the supplier must never rewrite.
 *
 * Timestamp-named because it depends on a 2026_* migration; a 0NNN_ file would
 * sort before it and run before the constraint exists.
 */
return new class extends Migration
{
    private const STATUSES = [
        'draft',
        'pending_approval',
        'approved',
        'sent',
        'acknowledged',
        'supplier_proposed',
        'supplier_declined',
        'partially_received',
        'received',
        'closed',
        'cancelled',
    ];

    public function up(): void
    {
        if (Schema::hasTable('purchase_orders') && ! Schema::hasColumn('purchase_orders', 'confirmed_delivery_date')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->date('confirmed_delivery_date')->nullable()->after('expected_delivery_date');
            });
        }

        $this->replaceConstraint(self::STATUSES);
    }

    public function down(): void
    {
        $invalid = DB::table('purchase_orders')
            ->whereIn('status', ['acknowledged', 'supplier_proposed', 'supplier_declined'])
            ->exists();
        if ($invalid) {
            throw new RuntimeException(
                'Cannot narrow purchase_orders.status: supplier-response rows exist. Resolve them first.',
            );
        }

        $this->replaceConstraint([
            'draft', 'pending_approval', 'approved', 'sent',
            'partially_received', 'received', 'closed', 'cancelled',
        ]);

        if (Schema::hasTable('purchase_orders') && Schema::hasColumn('purchase_orders', 'confirmed_delivery_date')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->dropColumn('confirmed_delivery_date');
            });
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function replaceConstraint(array $allowed): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }
        if (! Schema::hasTable('purchase_orders') || ! Schema::hasColumn('purchase_orders', 'status')) {
            return;
        }

        $name = 'purchase_orders_status_lifecycle_check';
        $values = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $allowed,
        ));

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "purchase_orders" DROP CONSTRAINT IF EXISTS "'.$name.'"');
            DB::statement('ALTER TABLE "purchase_orders" ADD CONSTRAINT "'.$name.'" CHECK ("status" IN ('.$values.') OR "status" IS NULL)');

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_insert_guard"');
        DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_update_guard"');
        DB::statement('CREATE TRIGGER "'.$name.'_insert_guard" BEFORE INSERT ON "purchase_orders" WHEN NEW."status" IS NOT NULL AND NEW."status" NOT IN ('.$values.') BEGIN SELECT RAISE(ABORT, \'invalid purchase_orders.status\'); END');
        DB::statement('CREATE TRIGGER "'.$name.'_update_guard" BEFORE UPDATE OF "status" ON "purchase_orders" WHEN NEW."status" IS NOT NULL AND NEW."status" NOT IN ('.$values.') BEGIN SELECT RAISE(ABORT, \'invalid purchase_orders.status\'); END');
    }
};
