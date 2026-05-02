<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->foreignId('department_id')->constrained('departments');
            $table->string('salary_grade', 20)->nullable();
            $table->timestamps();

            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
