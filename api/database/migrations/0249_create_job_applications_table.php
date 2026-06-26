<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $t) {
            $t->id();
            $t->string('application_number', 20)->unique();
            $t->foreignId('job_posting_id')->constrained('job_postings');
            $t->string('tracking_code', 10)->unique();
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('email', 255);
            $t->string('phone', 30);
            $t->string('resume_path', 500);
            $t->string('resume_original_name', 255);
            $t->text('cover_letter')->nullable();
            $t->string('stage', 20)->default('new');
            $t->string('rejected_at_stage', 20)->nullable();
            $t->text('rejection_reason')->nullable();
            $t->foreignId('converted_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->timestamp('applied_at')->useCurrent();
            $t->timestamps();

            $t->index(['job_posting_id', 'stage']);
            $t->index('email');
            $t->index('tracking_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
