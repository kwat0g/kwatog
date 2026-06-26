<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_interviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_application_id')->constrained('job_applications')->cascadeOnDelete();
            $t->timestamp('scheduled_at');
            $t->string('location', 200)->nullable();
            $t->string('interviewer_name', 200);
            $t->text('notes')->nullable();
            $t->string('outcome', 20)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_interviews');
    }
};
