<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bind outgoing QC to the exact finished-goods output batch it releases.
 *
 * Legacy outgoing rows remain readable and uniquely WO-scoped, but their
 * nullable output link and zero accepted quantity intentionally prevent them
 * from authorizing new deliveries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            if (! Schema::hasColumn('inspections', 'work_order_output_id')) {
                $table->foreignId('work_order_output_id')->nullable()->after('entity_id')
                    ->constrained('work_order_outputs')->nullOnDelete();
            }
            if (! Schema::hasColumn('inspections', 'accepted_quantity')) {
                $table->unsignedInteger('accepted_quantity')->default(0)->after('batch_quantity');
            }
        });

        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Outgoing inspection provenance migration requires a partial-index capable driver; received {$driver}."
            );
        }

        DB::statement('DROP INDEX IF EXISTS inspections_non_incoming_entity_unique');

        DB::statement(
            'CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique '
            .'ON inspections (stage, entity_type, entity_id) '
            ."WHERE stage <> 'incoming' AND stage <> 'outgoing'"
        );
        DB::statement(
            'CREATE UNIQUE INDEX inspections_legacy_outgoing_entity_unique '
            .'ON inspections (stage, entity_type, entity_id) '
            ."WHERE stage = 'outgoing' AND work_order_output_id IS NULL"
        );
        DB::statement(
            'CREATE UNIQUE INDEX inspections_outgoing_output_unique '
            .'ON inspections (stage, work_order_output_id) '
            ."WHERE stage = 'outgoing' AND work_order_output_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        // The pre-0468 index can represent only one outgoing inspection per
        // WO. Refuse a destructive rollback when output-bound evidence has
        // already created multiple inspections for the same WO; operators
        // must preserve the QC rows and migrate them explicitly instead.
        $duplicateOutgoingWorkOrders = DB::table('inspections')
            ->select('entity_type', 'entity_id')
            ->where('stage', 'outgoing')
            ->whereNotNull('entity_type')
            ->whereNotNull('entity_id')
            ->groupBy('entity_type', 'entity_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateOutgoingWorkOrders) {
            throw new RuntimeException(
                'Cannot roll back outgoing inspection provenance: multiple outgoing QC rows exist for a work order. Preserve QC evidence and migrate duplicates explicitly.'
            );
        }

        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS inspections_non_outgoing_entity_unique');
            DB::statement('DROP INDEX IF EXISTS inspections_legacy_outgoing_entity_unique');
            DB::statement('DROP INDEX IF EXISTS inspections_outgoing_output_unique');
            DB::statement(
                'CREATE UNIQUE INDEX inspections_non_incoming_entity_unique '
                .'ON inspections (stage, entity_type, entity_id) '
                ."WHERE stage <> 'incoming'"
            );
        }

        Schema::table('inspections', function (Blueprint $table): void {
            if (Schema::hasColumn('inspections', 'work_order_output_id')) {
                $table->dropConstrainedForeignId('work_order_output_id');
            }
            if (Schema::hasColumn('inspections', 'accepted_quantity')) {
                $table->dropColumn('accepted_quantity');
            }
        });
    }
};
