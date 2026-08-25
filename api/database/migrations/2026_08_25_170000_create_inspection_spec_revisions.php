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
        if (! Schema::hasTable('inspection_spec_revisions')) {
            Schema::create('inspection_spec_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('inspection_spec_id')
                    ->constrained('inspection_specs')
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('version');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['inspection_spec_id', 'version']);
                $table->index(['inspection_spec_id', 'created_at']);
            });
        }

        if (! Schema::hasColumn('inspection_spec_items', 'inspection_spec_revision_id')) {
            Schema::table('inspection_spec_items', function (Blueprint $table): void {
                $table->foreignId('inspection_spec_revision_id')
                    ->nullable()
                    ->after('inspection_spec_id')
                    ->constrained('inspection_spec_revisions')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('inspections', 'inspection_spec_revision_id')) {
            Schema::table('inspections', function (Blueprint $table): void {
                $table->foreignId('inspection_spec_revision_id')
                    ->nullable()
                    ->after('inspection_spec_id')
                    ->constrained('inspection_spec_revisions')
                    ->restrictOnDelete();
            });
        }

        if (Schema::hasTable('inspection_measurements')) {
            Schema::table('inspection_measurements', function (Blueprint $table): void {
                $table->dropForeign(['inspection_spec_item_id']);
                $table->foreign('inspection_spec_item_id')
                    ->references('id')
                    ->on('inspection_spec_items')
                    ->restrictOnDelete();
            });
        }

        // Existing installations only have the latest item definition. Treat
        // it as the first reconstructable revision instead of inventing older
        // versions that were never stored.
        DB::table('inspection_specs')
            ->orderBy('id')
            ->get(['id', 'version', 'created_by', 'notes', 'created_at', 'updated_at'])
            ->each(function (object $spec): void {
                $revisionId = DB::table('inspection_spec_revisions')
                    ->where('inspection_spec_id', $spec->id)
                    ->where('version', $spec->version)
                    ->value('id');

                if (! $revisionId) {
                    $revisionId = DB::table('inspection_spec_revisions')->insertGetId([
                        'inspection_spec_id' => $spec->id,
                        'version' => $spec->version,
                        'created_by' => $spec->created_by,
                        'notes' => $spec->notes,
                        'created_at' => $spec->created_at,
                        'updated_at' => $spec->updated_at,
                    ]);
                }

                DB::table('inspection_spec_items')
                    ->where('inspection_spec_id', $spec->id)
                    ->whereNull('inspection_spec_revision_id')
                    ->update(['inspection_spec_revision_id' => $revisionId]);

                DB::table('inspections')
                    ->where('inspection_spec_id', $spec->id)
                    ->whereNull('inspection_spec_revision_id')
                    ->update(['inspection_spec_revision_id' => $revisionId]);
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('inspection_measurements')) {
            Schema::table('inspection_measurements', function (Blueprint $table): void {
                $table->dropForeign(['inspection_spec_item_id']);
                $table->foreign('inspection_spec_item_id')
                    ->references('id')
                    ->on('inspection_spec_items')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('inspections', 'inspection_spec_revision_id')) {
            Schema::table('inspections', function (Blueprint $table): void {
                $table->dropForeign(['inspection_spec_revision_id']);
                $table->dropColumn('inspection_spec_revision_id');
            });
        }

        if (Schema::hasColumn('inspection_spec_items', 'inspection_spec_revision_id')) {
            Schema::table('inspection_spec_items', function (Blueprint $table): void {
                $table->dropForeign(['inspection_spec_revision_id']);
                $table->dropColumn('inspection_spec_revision_id');
            });
        }

        Schema::dropIfExists('inspection_spec_revisions');
    }
};
