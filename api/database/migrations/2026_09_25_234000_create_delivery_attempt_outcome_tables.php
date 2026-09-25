<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_items', function (Blueprint $table): void {
            $table->decimal('customer_received_quantity', 15, 3)->nullable()->after('quantity');
        });

        Schema::create('delivery_attempt_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->unique()->constrained('deliveries')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('payload_fingerprint', 64);
            $table->string('reason_code', 40);
            $table->text('notes')->nullable();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reported_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('receipt_request_key')->nullable()->unique();
            $table->char('receipt_payload_fingerprint', 64)->nullable();
            $table->foreignId('quarantine_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->text('variance_reason')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->foreignId('return_request_id')->nullable()->unique()->constrained('return_requests')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['reported_at', 'reported_by']);
        });

        Schema::create('delivery_attempt_outcome_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_attempt_outcome_id')->constrained('delivery_attempt_outcomes')->cascadeOnDelete();
            $table->foreignId('delivery_item_id')->constrained('delivery_items')->restrictOnDelete();
            $table->decimal('shipped_quantity', 15, 3);
            $table->decimal('customer_received_quantity', 15, 3);
            $table->decimal('customer_received_damaged_quantity', 15, 3)->default(0);
            $table->decimal('truck_return_quantity', 15, 3);
            $table->decimal('truck_return_damaged_quantity', 15, 3)->default(0);
            $table->decimal('declared_unaccounted_quantity', 15, 3);
            $table->decimal('warehouse_received_quantity', 15, 3)->nullable();
            $table->decimal('unaccounted_quantity', 15, 3)->nullable();
            $table->timestampsTz();
            $table->unique(['delivery_attempt_outcome_id', 'delivery_item_id'], 'delivery_attempt_outcome_items_line_unique');
            $table->index('delivery_item_id');
        });

        Schema::create('delivery_attempt_outcome_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_attempt_outcome_item_id')->constrained('delivery_attempt_outcome_items')->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->decimal('declared_quantity', 15, 3);
            $table->decimal('received_quantity', 15, 3)->nullable();
            $table->timestampsTz();
            $table->unique(['delivery_attempt_outcome_item_id', 'stock_movement_id'], 'delivery_attempt_outcome_movements_issue_unique');
        });

        Schema::table('return_requests', function (Blueprint $table): void {
            $table->foreignId('delivery_attempt_outcome_id')->nullable()->unique()
                ->constrained('delivery_attempt_outcomes')->restrictOnDelete();
        });

        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->foreignId('delivery_attempt_outcome_movement_id')->nullable()->unique()
                ->constrained('delivery_attempt_outcome_movements')->restrictOnDelete();
        });

        // Deliveries with goods still on the truck cannot be confirmed or
        // cancelled. The new terminal `returned` represents a depot-verified
        // attempt where the customer accepted no goods.
        $this->replaceDeliveryStatusGuard([
            'scheduled', 'loading', 'in_transit', 'return_pending', 'delivered', 'confirmed', 'returned', 'cancelled',
        ]);

        DB::table('delivery_items')
            ->whereIn('delivery_id', DB::table('deliveries')->whereIn('status', ['delivered', 'confirmed'])->select('id'))
            ->whereNull('customer_received_quantity')
            ->update(['customer_received_quantity' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        $this->replaceDeliveryStatusGuard(['scheduled', 'loading', 'in_transit', 'delivered', 'confirmed', 'cancelled']);

        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_attempt_outcome_movement_id');
        });
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_attempt_outcome_id');
        });
        Schema::dropIfExists('delivery_attempt_outcome_movements');
        Schema::dropIfExists('delivery_attempt_outcome_items');
        Schema::dropIfExists('delivery_attempt_outcomes');
        Schema::table('delivery_items', function (Blueprint $table): void {
            $table->dropColumn('customer_received_quantity');
        });
    }

    /** @param list<string> $values */
    private function replaceDeliveryStatusGuard(array $values): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE deliveries DROP CONSTRAINT IF EXISTS deliveries_status_check');
            DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_status_check CHECK (status IN ('.implode(',', array_map(static fn (string $value): string => "'".$value."'", $values)).'))');
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS deliveries_status_check_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS deliveries_status_check_update_guard');
            $quoted = implode(',', array_map(static fn (string $value): string => "'".$value."'", $values));
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $suffix = strtolower($operation);
                DB::statement("CREATE TRIGGER deliveries_status_check_{$suffix}_guard BEFORE {$operation} ON deliveries WHEN NEW.status IS NOT NULL AND NEW.status NOT IN ({$quoted}) BEGIN SELECT RAISE(ABORT, 'invalid deliveries.status'); END");
            }
        }
    }
};
