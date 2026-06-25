<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_cycles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('cycle_type', 30);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 20)->default('draft');
            $t->text('description')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('review_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->text('description')->nullable();
            $t->json('criteria');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('performance_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('review_cycle_id')->constrained('review_cycles');
            $t->foreignId('employee_id')->constrained('employees');
            $t->foreignId('reviewer_id')->constrained('employees');
            $t->foreignId('template_id')->nullable()->constrained('review_templates');
            $t->string('status', 20)->default('pending');
            $t->json('ratings')->nullable();
            $t->text('strengths')->nullable();
            $t->text('improvements')->nullable();
            $t->text('goals')->nullable();
            $t->decimal('overall_score', 3, 2)->nullable();
            $t->string('overall_rating', 30)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['review_cycle_id', 'employee_id', 'reviewer_id']);
            $t->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('review_templates');
        Schema::dropIfExists('review_cycles');
    }
};
