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
        Schema::table('customers', function (Blueprint $table): void {
            $table->jsonb('allowed_payment_methods')->nullable();
            $table->string('shipping_payer')->nullable();
            $table->date('agreement_starts_on')->nullable();
            $table->date('agreement_ends_on')->nullable();
            $table->unsignedInteger('warranty_duration_days')->nullable();
            $table->unsignedInteger('warranty_report_window_days')->nullable();
            $table->string('warranty_start_basis')->nullable();
            $table->jsonb('warranty_covered_issues')->nullable();
            $table->jsonb('warranty_remedies')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('customers')
            ->whereNotNull('allowed_payment_methods')
            ->orWhereNotNull('shipping_payer')
            ->orWhereNotNull('agreement_starts_on')
            ->orWhereNotNull('agreement_ends_on')
            ->orWhereNotNull('warranty_duration_days')
            ->orWhereNotNull('warranty_report_window_days')
            ->orWhereNotNull('warranty_start_basis')
            ->orWhereNotNull('warranty_covered_issues')
            ->orWhereNotNull('warranty_remedies')
            ->exists()) {
            throw new RuntimeException('Cannot roll back customer terms while customer records use them. Export or migrate that data first.');
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'allowed_payment_methods', 'shipping_payer', 'agreement_starts_on', 'agreement_ends_on',
                'warranty_duration_days', 'warranty_report_window_days', 'warranty_start_basis',
                'warranty_covered_issues', 'warranty_remedies',
            ]);
        });
    }
};
