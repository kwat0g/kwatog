<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_advance_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_advance_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('applied_at');
            $table->timestampsTz();
            $table->unique(['sales_order_advance_id', 'invoice_id'], 'advance_invoice_application_unique');
            $table->index(['invoice_id', 'applied_at'], 'advance_application_invoice_date_index');
            $table->index(['sales_order_advance_id', 'applied_at'], 'advance_application_advance_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_advance_applications');
    }
};
