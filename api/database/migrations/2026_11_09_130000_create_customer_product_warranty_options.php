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
        Schema::create('customer_product_warranty_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedInteger('warranty_duration_days')->nullable();
            $table->unsignedInteger('warranty_report_window_days')->nullable();
            $table->string('warranty_start_basis')->nullable();
            $table->jsonb('warranty_covered_issues')->nullable();
            $table->jsonb('warranty_remedies')->nullable();
            $table->text('additional_terms')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['customer_id', 'product_id', 'label'], 'customer_product_warranty_option_label_unique');
            $table->index(['customer_id', 'product_id', 'position'], 'customer_product_warranty_option_order_index');
        });

        DB::table('customer_product_warranty_overrides')->orderBy('id')->each(function (object $row): void {
            $terms = [
                'warranty_duration_days' => $row->warranty_duration_days,
                'warranty_report_window_days' => $row->warranty_report_window_days,
                'warranty_start_basis' => $row->warranty_start_basis,
                'warranty_covered_issues' => $row->warranty_covered_issues,
                'warranty_remedies' => $row->warranty_remedies,
                'additional_terms' => null,
            ];
            if (collect($terms)->every(static fn (mixed $value): bool => $value === null)) {
                return;
            }

            $duration = $row->warranty_duration_days === null ? null : (int) $row->warranty_duration_days;
            DB::table('customer_product_warranty_options')->insert([
                'customer_id' => $row->customer_id,
                'product_id' => $row->product_id,
                'label' => $duration === null ? 'Existing warranty terms' : "{$duration}-day warranty",
                'warranty_duration_days' => $row->warranty_duration_days,
                'warranty_report_window_days' => $row->warranty_report_window_days,
                'warranty_start_basis' => $row->warranty_start_basis,
                'warranty_covered_issues' => $row->warranty_covered_issues,
                'warranty_remedies' => $row->warranty_remedies,
                'additional_terms' => null,
                'position' => 0,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        if (DB::table('customer_product_warranty_options')->exists()) {
            throw new RuntimeException('Cannot roll back customer warranty choices while customer agreements use them. Export or migrate that data first.');
        }

        Schema::dropIfExists('customer_product_warranty_options');
    }
};
