<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $t) {
            $t->id();
            $t->string('posting_number', 20)->unique();
            $t->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $t->foreignId('department_id')->constrained('departments');
            $t->string('title', 200);
            $t->text('description');
            $t->text('requirements');
            $t->string('employment_type', 30);
            $t->decimal('salary_range_min', 15, 2)->nullable();
            $t->decimal('salary_range_max', 15, 2)->nullable();
            $t->boolean('show_salary')->default(false);
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('slots')->default(1);
            $t->timestamp('posted_at')->nullable();
            $t->timestamp('closes_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->softDeletes();

            $t->index(['status', 'posted_at']);
            $t->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
