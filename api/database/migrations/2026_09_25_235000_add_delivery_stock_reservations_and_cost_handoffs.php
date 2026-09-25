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
        Schema::table('deliveries', function (Blueprint $table): void {
            // Old rows stay on their historic accounting policy. DeliveryService
            // opts only newly created records into the transit-costing cutover.
            $table->string('cost_recognition_mode', 20)->default('legacy')->index();
        });

        Schema::create('delivery_stock_reservation_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->unique()->constrained('deliveries')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('payload_fingerprint', 64);
            $table->string('status', 20)->default('reserved');
            $table->foreignId('reserved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reserved_at');
            $table->timestampsTz();
        });

        Schema::create('delivery_stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_batch_id')->constrained('delivery_stock_reservation_batches')->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('delivery_item_id')->constrained('delivery_items')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('lot_number', 100);
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('consumed_quantity', 15, 3)->default(0);
            $table->decimal('released_quantity', 15, 3)->default(0);
            $table->string('status', 20)->default('reserved');
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['item_id', 'location_id', 'lot_number', 'status'], 'delivery_stock_hold_lookup_idx');
            $table->index(['delivery_item_id', 'status'], 'delivery_stock_line_hold_idx');
        });

        Schema::create('delivery_cost_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('delivery_attempt_outcome_id')->nullable()
                ->constrained('delivery_attempt_outcomes')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('payload_fingerprint', 64);
            $table->string('handoff_type', 30);
            $table->string('status', 30);
            $table->decimal('target_amount', 15, 2)->default(0);
            // Signed: negative values reverse a previously recognized loss.
            $table->decimal('delta_amount', 15, 2)->default(0);
            $table->json('source_allocations')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampsTz();
            $table->index(['delivery_id', 'handoff_type', 'id'], 'delivery_cost_handoff_read_idx');
        });

        // One receipt movement may economically restore several dispatch
        // movements (a delivery line can be split across bins). This bridge is
        // the source-cost audit, while stock_movements remains the canonical
        // physical ledger and its movement stays one row per counted RRI.
        Schema::create('delivery_customer_return_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_item_id')->constrained('return_request_items')->restrictOnDelete();
            $table->foreignId('source_stock_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('total_cost', 15, 2);
            $table->timestampsTz();
            $table->unique(['return_request_item_id', 'source_stock_movement_id', 'stock_movement_id'], 'delivery_customer_return_alloc_unique');
            $table->index(['source_stock_movement_id', 'created_at'], 'delivery_customer_return_source_idx');
        });

        $this->seedTransitAccountsAndSettings();
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_customer_return_allocations');
        Schema::dropIfExists('delivery_cost_handoffs');
        Schema::dropIfExists('delivery_stock_reservations');
        Schema::dropIfExists('delivery_stock_reservation_batches');
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropColumn('cost_recognition_mode'));

        // Deliberately retain the migration-seeded COA rows and settings: posted
        // journals may refer to them even if an operator rolls back the feature.
    }

    private function seedTransitAccountsAndSettings(): void
    {
        if (Schema::hasTable('accounts')) {
            $now = now();
            $parent = DB::table('accounts')->where('code', '1000')->value('id');
            foreach ([
                ['1240', 'Inventory - Delivery Transit', 'asset', 'debit', $parent],
                ['6135', 'Delivery Loss Expense', 'expense', 'debit', DB::table('accounts')->where('code', '6000')->value('id')],
            ] as [$code, $name, $type, $normal, $parentId]) {
                // A deployed chart is operator-owned data. Seed a missing
                // control account once, but never rewrite a pre-existing row
                // that may already be used by another configured role.
                DB::table('accounts')->insertOrIgnore([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'normal_balance' => $normal,
                    'parent_id' => $parentId,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();
        foreach ([
            ['accounting.accounts.inventory_delivery_transit_code', '1240', 'Delivery Transit Inventory Account Code', 'Asset account holding finished goods after dispatch and before customer receipt, return, or loss recognition.'],
            ['accounting.accounts.delivery_cogs_code', '5010', 'Delivery Cost of Goods Sold Account Code', 'Expense account debited for the immutable cost of goods actually accepted by a customer.'],
            ['accounting.accounts.delivery_loss_code', '6135', 'Delivery Loss Expense Account Code', 'Expense account debited for shipment quantities reconciled as unaccounted after depot count.'],
        ] as [$key, $value, $label, $description]) {
            // Keep configured values stable across upgrades. AccountPolicy
            // validates the seeded/default code on use and leaves incompatible
            // deployment mappings in a durable manual-required handoff.
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'group' => 'accounting',
                'label' => $label,
                'description' => $description,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }
    }
};
