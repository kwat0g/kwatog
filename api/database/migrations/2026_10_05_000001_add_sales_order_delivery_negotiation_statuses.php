<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = ['draft', 'confirmed', 'in_production', 'partially_delivered', 'delivered', 'invoiced', 'paid', 'closed', 'cancelled'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_orders ALTER COLUMN status TYPE VARCHAR(32)');
        }
        $this->guard([...self::OLD, 'awaiting_confirmation', 'delivery_negotiation']);
        DB::table('sales_orders')->where('submission_source', 'customer_portal')->where('status', 'draft')
            ->update(['status' => 'awaiting_confirmation']);
        DB::table('sales_orders')->where('submission_source', 'customer_portal')->where('status', 'awaiting_confirmation')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('sales_order_responses')->whereColumn('sales_order_id', 'sales_orders.id')
                    ->where('response_type', 'delivery_date')->whereIn('status', ['pending', 'rejected'])
                    ->whereRaw('sales_order_responses.id = (SELECT MAX(latest.id) FROM sales_order_responses latest WHERE latest.sales_order_id = sales_orders.id)');
            })->update(['status' => 'delivery_negotiation']);
    }

    public function down(): void
    {
        // Preserve every order and proposal when older code is restored.
        DB::table('sales_orders')->whereIn('status', ['awaiting_confirmation', 'delivery_negotiation'])->update(['status' => 'draft']);
        $this->guard(self::OLD);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_orders ALTER COLUMN status TYPE VARCHAR(20)');
        }
    }

    private function guard(array $statuses): void
    {
        $name = 'sales_orders_status_lifecycle_check';
        $values = implode(',', array_map(static fn (string $value): string => "'".$value."'", $statuses));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS '.$name);
            DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT '.$name.' CHECK (status IN ('.$values.') OR status IS NULL)');
            return;
        }
        if (DB::getDriverName() !== 'sqlite') throw new RuntimeException('Unsupported sales-order lifecycle database.');
        foreach (['insert', 'update'] as $operation) {
            DB::statement('DROP TRIGGER IF EXISTS '.$name.'_'.$operation.'_guard');
            DB::statement('CREATE TRIGGER '.$name.'_'.$operation.'_guard BEFORE '.strtoupper($operation).' ON sales_orders WHEN NEW.status IS NOT NULL AND NEW.status NOT IN ('.$values.") BEGIN SELECT RAISE(ABORT, 'invalid sales_orders.status'); END");
        }
    }
};
