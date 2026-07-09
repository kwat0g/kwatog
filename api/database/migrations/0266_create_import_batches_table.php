<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-03 — master-data import batches.
 *
 * One row per uploaded CSV import. Tracks the outcome (staged preview vs
 * committed vs rolled-back) and the row counts so a go-live cutover is
 * auditable and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);          // coa | items | ...
            $table->string('filename', 255)->nullable();
            $table->string('status', 20)->default('committed'); // committed | rolled_back
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('errors')->nullable();          // [{row, message}, ...] captured at commit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
