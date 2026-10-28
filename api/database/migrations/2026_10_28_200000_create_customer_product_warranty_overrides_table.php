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
        Schema::create('customer_product_warranty_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('warranty_duration_days')->nullable();
            $table->unsignedInteger('warranty_report_window_days')->nullable();
            $table->string('warranty_start_basis')->nullable();
            $table->jsonb('warranty_covered_issues')->nullable();
            $table->jsonb('warranty_remedies')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('customer_product_warranty_overrides')->exists()) {
            throw new RuntimeException('Cannot roll back customer-product warranty overrides while records use them. Export or migrate that data first.');
        }

        Schema::dropIfExists('customer_product_warranty_overrides');
    }
};
