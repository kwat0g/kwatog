<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_property', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('item_name', 200);
            $table->text('description')->nullable();
            $table->integer('quantity')->default(1);
            $table->date('date_issued');
            $table->date('date_returned')->nullable();
            $table->string('status', 20)->default('issued'); // issued|returned|lost
            $table->timestamps();

            $table->index('employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_property');
    }
};
