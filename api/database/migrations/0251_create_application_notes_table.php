<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_application_id')->constrained('job_applications')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_notes');
    }
};
