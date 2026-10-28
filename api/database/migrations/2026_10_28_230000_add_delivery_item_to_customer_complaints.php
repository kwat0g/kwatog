<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table): void {
            $table->foreignId('delivery_item_id')->nullable()->constrained('delivery_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_item_id');
        });
    }
};
