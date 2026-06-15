<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_revision_id')
                ->constrained('document_revisions')->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['document_revision_id', 'user_id'], 'uq_doc_ack_rev_user');
            $table->index(['user_id', 'acknowledged_at'], 'ix_doc_ack_user_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_acknowledgments');
    }
};
