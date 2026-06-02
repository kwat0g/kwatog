<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV7 — NCR templates for reusable non-conformance report setup.
 *
 * Pre-fills source, severity, product, defect description, and notes
 * so QC officers can file common NCR types in one click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ncr_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('source', 30);                        // inspection_fail | customer_complaint
            $table->string('severity', 20)->default('medium');    // low | medium | high | critical
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->text('defect_description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ncr_templates');
    }
};
