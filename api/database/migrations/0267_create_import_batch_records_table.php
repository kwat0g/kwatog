<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-03 — polymorphic record of every model a batch created, so a batch can
 * be rolled back (delete exactly what it inserted) without adding an
 * import_batch_id column to every imported entity's table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batch_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->string('recordable_type');
            $table->unsignedBigInteger('recordable_id');
            $table->timestamps();

            $table->index(['import_batch_id']);
            $table->index(['recordable_type', 'recordable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_records');
    }
};
