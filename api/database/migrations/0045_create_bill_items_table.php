<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity',   12, 2);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total',      15, 2);

            $table->index('bill_id');
            $table->index('expense_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_items');
    }
};
