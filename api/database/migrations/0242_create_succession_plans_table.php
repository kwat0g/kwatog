<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('succession_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('position_id')->constrained('positions');
            $t->foreignId('incumbent_id')->nullable()->constrained('employees');
            $t->foreignId('successor_id')->constrained('employees');
            $t->string('readiness', 30);
            $t->string('priority', 20)->default('medium');
            $t->text('development_notes')->nullable();
            $t->date('target_date')->nullable();
            $t->string('status', 20)->default('active');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->softDeletes();

            $t->index(['position_id', 'status']);
            $t->index(['successor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('succession_plans');
    }
};
