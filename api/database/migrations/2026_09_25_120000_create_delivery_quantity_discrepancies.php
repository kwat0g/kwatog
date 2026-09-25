<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_quantity_discrepancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->unique()->constrained('deliveries');
            $table->foreignId('reported_by')->constrained('customer_portal_users');
            $table->json('lines');
            $table->text('rationale');
            $table->string('status', 20)->default('pending');
            $table->text('resolution_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('customer_portal_users');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_quantity_discrepancies');
    }
};
