<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 10)->unique();
            $table->decimal('default_balance', 5, 1);
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_document')->default(false);
            $table->boolean('is_convertible_on_separation')->default(false);
            $table->boolean('is_convertible_year_end')->default(false);
            $table->decimal('conversion_rate', 3, 2)->default(1.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
