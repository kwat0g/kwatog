<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_quality_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('stage', 30)->default('incoming');
            $table->string('sampling_method', 20)->default('aql');
            $table->unsignedInteger('fixed_sample_size')->nullable();
            $table->string('aql_level', 20)->nullable();
            $table->json('parameters');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'vendor_id', 'version'], 'item_quality_plan_revision_unique');
            $table->index(['item_id', 'vendor_id', 'is_active'], 'item_quality_plan_lookup');
        });

        Schema::table('inspections', function (Blueprint $table) {
            $table->foreignId('item_quality_plan_id')->nullable()->after('inspection_spec_id')
                ->constrained('item_quality_plans')->nullOnDelete();
            $table->foreignId('grn_item_id')->nullable()->after('entity_id')
                ->constrained('grn_items')->nullOnDelete();
            $table->unique(['grn_item_id', 'stage'], 'inspection_grn_line_stage_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropUnique('inspection_grn_line_stage_unique');
            $table->dropConstrainedForeignId('grn_item_id');
            $table->dropConstrainedForeignId('item_quality_plan_id');
        });
        Schema::dropIfExists('item_quality_plans');
    }
};
