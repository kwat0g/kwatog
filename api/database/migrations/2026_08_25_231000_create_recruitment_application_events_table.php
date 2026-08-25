<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_application_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id')
                ->constrained('job_applications')
                ->cascadeOnDelete();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_type', 20)->default('user');
            $table->string('event_type', 60);
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20)->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['job_application_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_application_events');
    }
};
