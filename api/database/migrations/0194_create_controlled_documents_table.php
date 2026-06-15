<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controlled_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('title', 200);
            $table->string('category', 30); // sop|work_instruction|form|spec|policy
            $table->text('description')->nullable();
            $table->string('assignee_role', 60); // role.slug; users with this role must ack
            $table->unsignedSmallInteger('review_interval_months')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamp('last_review_alert_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category', 'ix_ctrl_docs_category');
            $table->index('is_active', 'ix_ctrl_docs_active');
            $table->index('assignee_role', 'ix_ctrl_docs_assignee_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_documents');
    }
};
