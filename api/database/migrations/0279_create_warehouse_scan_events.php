<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('barcode', 255);
            $table->string('result_type', 50);
            $table->boolean('is_recognized');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'is_recognized']);
            $table->index('result_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_scan_events');
    }
};
