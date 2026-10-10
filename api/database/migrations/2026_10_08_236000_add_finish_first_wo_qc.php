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
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('qc_workflow', 32)->nullable();
        });

        Schema::create('inspection_defective_pieces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_id')->constrained('inspections')->restrictOnDelete();
            $table->unsignedInteger('piece_number');
            $table->string('problem_description', 500);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['inspection_id', 'piece_number']);
        });

        Schema::create('inspection_piece_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_id')->constrained('inspections')->restrictOnDelete();
            $table->foreignId('requirement_id')->constrained('inspection_measurements')->restrictOnDelete();
            $table->unsignedInteger('piece_number');
            $table->decimal('measured_value', 12, 4);
            $table->boolean('is_pass');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['inspection_id', 'requirement_id', 'piece_number'], 'inspection_piece_reading_unique');
        });

        DB::statement('ALTER TABLE inspection_defective_pieces ADD CONSTRAINT inspection_defective_piece_number_check CHECK (piece_number > 0)');
        DB::statement('ALTER TABLE inspection_piece_readings ADD CONSTRAINT inspection_piece_reading_number_check CHECK (piece_number > 0)');
    }

    public function down(): void
    {
        if (DB::table('work_orders')->whereNotNull('qc_workflow')->exists()
            || DB::table('inspection_defective_pieces')->exists()
            || DB::table('inspection_piece_readings')->exists()) {
            throw new RuntimeException('Finish-first QC policy or piece evidence exists. Preserve the additive schema during application rollback.');
        }

        Schema::dropIfExists('inspection_piece_readings');
        Schema::dropIfExists('inspection_defective_pieces');
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn('qc_workflow'));
    }
};
