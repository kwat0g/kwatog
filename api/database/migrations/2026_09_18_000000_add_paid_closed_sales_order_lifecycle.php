<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const OLD_STATUSES = [
        'draft', 'confirmed', 'in_production', 'partially_delivered',
        'delivered', 'invoiced', 'cancelled',
    ];

    /** @var list<string> */
    private const STATUSES = [
        'draft', 'confirmed', 'in_production', 'partially_delivered',
        'delivered', 'invoiced', 'paid', 'closed', 'cancelled',
    ];

    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
        });

        $this->replaceStatusGuard(self::STATUSES);
    }

    public function down(): void
    {
        $this->replaceStatusGuard(self::OLD_STATUSES);

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn(['paid_at', 'closed_at']);
        });
    }

    /** @param list<string> $allowed */
    private function replaceStatusGuard(array $allowed): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Sales-order lifecycle constraints require PostgreSQL or SQLite; received {$driver}.");
        }

        $invalid = DB::table('sales_orders')
            ->whereNotNull('status')
            ->whereNotIn('status', $allowed)
            ->distinct()
            ->pluck('status');
        if ($invalid->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot update the sales-order lifecycle guard; unsupported statuses exist: '.$invalid->implode(', '),
            );
        }

        $name = 'sales_orders_status_lifecycle_check';
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "sales_orders" DROP CONSTRAINT IF EXISTS "'.$name.'"');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_insert_guard"');
            DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_update_guard"');
        }

        $values = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $allowed,
        ));

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE "sales_orders" ADD CONSTRAINT "'.$name.'" CHECK ("status" IN ('.$values.') OR "status" IS NULL)',
            );
            return;
        }

        DB::statement(
            'CREATE TRIGGER "'.$name.'_insert_guard" BEFORE INSERT ON "sales_orders" '
            .'WHEN NEW."status" IS NOT NULL AND NEW."status" NOT IN ('.$values.') '
            .'BEGIN SELECT RAISE(ABORT, \'invalid sales_orders.status\'); END',
        );
        DB::statement(
            'CREATE TRIGGER "'.$name.'_update_guard" BEFORE UPDATE OF "status" ON "sales_orders" '
            .'WHEN NEW."status" IS NOT NULL AND NEW."status" NOT IN ('.$values.') '
            .'BEGIN SELECT RAISE(ABORT, \'invalid sales_orders.status\'); END',
        );
    }
};
